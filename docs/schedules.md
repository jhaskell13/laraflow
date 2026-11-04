# Scheduled workflows

> This document was written as the design contract for Milestone 10's schedules before any of it was built, and is now the shipped contract. The enforcing suites are `tests/Feature/Workflow/ScheduleTest.php` and `tests/Feature/Console/ScheduleCommandsTest.php`, with the scheduler race proven at the claim in the suite and across three real processes in the install walk. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.
>
> The commands and configuration this document names are shipped and held to it by the contracts test; the console rows live in [public-api.md](public-api.md).

## The problem this solves

An application can already run a workflow on a cadence with one line in its scheduler:

```php
Schedule::call(fn () => app(WorkflowDispatcher::class)->dispatch(new NightlyReconciliation))->dailyAt('02:00');
```

Three things are wrong with that line, and every application that writes it discovers them in production:

- **It runs once per scheduler host.** Two hosts running `schedule:run` start two executions. Laravel's `onOneServer()` narrows that to one *most of the time*, through a cache lock, which is a lease and not a record: a lock lost to a cache restart or an expired TTL is a second execution nobody can see coming.
- **A missed tick is silent.** A scheduler that was down from 01:50 to 02:10 never ran the 02:00 job, and nothing says so. Whether that job should run late, or be skipped and reported, is a decision the application never got to make.
- **Nothing records that a tick happened.** The execution exists, but what started it is a `created_at` that happens to be near 02:00. An operator asking "did last night's run happen, and if not, why" reads timestamps and guesses.

Schedules are that line, made durable: a workflow declares its cadence in code, Laraflow records every tick as a row, and starting the execution is the same transaction as claiming the tick — so one tick is one execution across any number of schedulers, by the database's uniqueness guarantee rather than by a lock.

## Shared principles

- **Declared in code, like everything else about a workflow.** The cadence is a method on the workflow class, reviewable and versionable with the steps it runs. Configuration says which scheduled workflows are live; it does not hold the cron expression, because a schedule nobody can find by reading the class is a schedule nobody maintains.
- **Once per tick by uniqueness, not by lock.** A tick is a row with a unique key on `(schedule, instant)`. Whoever inserts it starts the execution; everyone else is refused by the index. There is no lease to lose and no clock to trust but the one that names the tick.
- **Durable before dispatched.** The tick row and the execution it starts commit together; the queue push follows the commit, exactly as `WorkflowDispatcher` already orders things. A scheduler that dies between the two leaves a pending execution the next tick run heals, never a tick that fired without a record or a record of a tick that never fired.
- **A missed tick is a policy, not an accident.** What happens to ticks that passed while nobody was running is declared on the schedule and recorded on the row of the tick that ran next.
- **The package does not schedule itself.** One line in the application's scheduler runs Laraflow's tick, exactly as `laraflow:recover` and `laraflow:prune` are run today ([queue-and-recovery.md](queue-and-recovery.md#recovering-interrupted-work)). Laraflow decides *what* is due; the application decides *that* the scheduler runs.
- **Every scan bounded, nothing hidden.** A tick run handles at most a configured number of schedules and catch-up ticks, reports what it did, and exits non-zero when something needs a person.

## Declaring a schedule

`Workflow` gains one optional override, shaped like `version()` and `planMigrations()`:

```php
use Laraflow\Workflow\Schedule;
use Laraflow\Workflow\MissedTickPolicy;

class NightlyReconciliation extends Workflow
{
    public function schedule(): ?Schedule
    {
        return Schedule::cron('0 2 * * *')
            ->timezone('Europe/London')
            ->input(fn (DateTimeImmutable $tick): array => [
                'day' => $tick->modify('-1 day')->format('Y-m-d'),
            ])
            ->onMissedTicks(MissedTickPolicy::Skip)
            ->withoutOverlapping();
    }

    public function steps(): array { /* ... */ }
}
```

- **`Schedule::cron($expression)`** — a standard five-field cron expression or an `@alias`, validated where it is declared: an unparseable expression, and one that parses but never matches a real date (February 30th), are both refused at the declaration site — surfaced by the tick run as that class's failure, never as a crash at tick time. The six-field seconds form is refused: a tick is a minute, because the scheduler that runs it is.
- **`timezone($name)`** — the zone the expression is read in; the application's `app.timezone` when omitted. A tick names an *instant*: it is recorded the way every other Laraflow timestamp is recorded and rendered as UTC wherever it is displayed (the console, the dashboard, the correlation id). On the autumn change a repeated local hour fires once, on its first occurrence; on the spring change a tick in the skipped hour fires once, at the next valid local time.
- **`input($input)`** — a closure or a static array building the execution's input. A closure receives the tick instant as a `DateTimeImmutable`, so a nightly job knows which night it is for without reading the clock (which, under catch-up, would be the wrong night), and returns the array `start()` would be given. The result is bounded and validated exactly as any `start()` input.
- **`onMissedTicks($policy)`** — `MissedTickPolicy::Skip` (the default) or `MissedTickPolicy::CatchUp`; see [Missed ticks](#missed-ticks).
- **`withoutOverlapping()`** — do not start a tick's execution while the previous tick's execution is unfinished; see [Overlap](#overlap). Off by default: most scheduled work is independent per tick, and skipping is a decision an author should take knowingly.
- **`tenant($id)`** — record the tenant on every execution the schedule starts, for the scoping, throttling, and retention that identifier already drives. One schedule serves one tenant or none; a schedule that must run *per* tenant is a workflow whose first step fans out over them ([advanced-workflows.md](advanced-workflows.md#fan-out-and-fan-in)), which gives each tenant its own linked execution rather than multiplying schedules.

The declaration is the default. **A workflow has at most one schedule, and the workflow class is its identity**: the row that records it is keyed by the class name, and renaming the class is a new schedule, reported as such ([Removal and renaming](#removal-and-renaming)). A second cadence for the same steps is a second class — a subclass that overrides only `schedule()` — exactly as a second version is a changed `version()` and not a second table.

### Registration

A declaration is not an activation. A workflow with a `schedule()` is scheduled only once the application lists it:

```php
// config/laraflow.php
'schedules' => [
    'enabled' => env('LARAFLOW_SCHEDULES_ENABLED', true),
    'workflows' => [
        App\Workflows\NightlyReconciliation::class,
    ],
    // ...
],
```

The list is the application's statement of what runs in this environment — staging lists what staging runs — and the one kill switch, `enabled`, stops every schedule at once without unlisting them, for the incident where nothing should start. A package may register a scheduled workflow of its own through the registry from its service provider, as a connector package registers a connector through the manager; both paths end in the same registry, and there is no privileged one. A listed class that has no `schedule()`, or is not a `Workflow`, is refused by name when the registry is read, because a schedule that silently never fires is the failure this feature exists to remove.

## The tick

The scheduler is one command, run every minute by the application's scheduler exactly as the recover command is today:

```php
Schedule::command('laraflow:schedule')->everyMinute();
```
 (`withoutOverlapping()` on that line is harmless but unnecessary: two tick runs racing is the designed case, not a hazard.) One run does the following, per registered schedule, inside the configured limits:

1. **Synchronise** the declaration to its row: cron, timezone, policy, and overlap flag are written from code on every run, so the row is a cache of the class and never the authority. A row with no class behind it is left standing and reported ([Removal and renaming](#removal-and-renaming)).
2. **Name the due tick.** The tick instant is the cron's most recent instant at or before *now* in the schedule's timezone, expressed in UTC. It is a function of the expression and the clock alone, so every scheduler host that reads the same minute names the same instant — that determinism is what makes the uniqueness guarantee mean anything. A run at `02:00:40` names `02:00:00`; a run at `02:01:00` on a schedule that does not fire at `02:01` names `02:00:00` again, and finds it already taken.
3. **Claim the tick** by inserting its row. The unique index on `(schedule, tick_at)` is the arbiter: one insert succeeds, every concurrent one is refused by the database and treated as *someone else has this tick* — a quiet, counted outcome, not an error. A tick already present from an earlier run is simply not due.
4. **Start the execution in the same transaction.** `start()` creates the execution and its plan; the tick row records the execution id; the schedule row's `last_tick_at` advances to this tick. The three commit together. A failure anywhere — an input the serialization contract refuses, a plan the definition rules refuse — rolls the tick back too, and the run reports it: the next run will find the tick still unclaimed and try again, which is right, because the failure was the code's and the code may have been fixed.
5. **Dispatch after the commit**, through `WorkflowDispatcher`, with `after_commit` ordering as today. The scheduler's own `--sync` runs the execution in-process instead — the lost-dispatch heal included — for the application with no queue, as `laraflow:recover --sync` does; an inline run whose step fails exits non-zero the way recover does, and a tenant-deferred delivery is reported as deferred, never as a failure.

Every execution a schedule starts carries a correlation id of the form `schedule:{ClassBasename}@{tick}` (the tick rendered as UTC, `Y-m-d\TH:i\Z`), so `--correlation=` finds a night's run with no new filter. The tick instant itself reaches the steps only through `input()`: the executions table gains no column for it, because the tick row already holds the link and `laraflow:show` reads it from there ([Observability](#observability)).

### A lost dispatch heals

A tick whose transaction committed but whose queue push failed — a broker outage, a worker that died inside the push — leaves a `pending` execution with a tick row pointing at it. The pending scan in `laraflow:recover` is opt-in for a reason stated in [queue-and-recovery.md](queue-and-recovery.md#what-it-looks-for): `pending` is also the state of work an application created and chose not to run yet. **A scheduled execution is never that.** It exists because a tick said *now*, so the scheduler re-dispatches, on every run, any execution its own ticks started that is still `pending` after `schedules.redispatch_after` seconds (default 300) — never dispatched, or dispatched that long ago and never picked up, since the dispatch mark lands before the push and a failed push leaves it standing. Each re-push advances the mark, so a backlogged queue is handed at most one duplicate per window (and a duplicate is a no-op by the claim). Bounded by the run's limit and reported as `re-dispatched`. The scan is served by the tick table's execution index and reads only executions ticks created; it never touches a `pending` execution the application started.

## Missed ticks

A tick is *missed* when its instant passed while no scheduler run claimed it: the scheduler was down, the host was asleep, or `enabled` was off. The run that comes back finds `last_tick_at` behind the current due instant and applies the schedule's policy to the gap:

| Policy | Behaviour | Recorded as |
|---|---|---|
| **`Skip`** (default) | Only the most recent due tick is claimed and run. The ticks between `last_tick_at` and it are not run. | The fired tick's row carries `missed` — how many instants were skipped — and `missed_from`, the earliest of them. An hour's outage on a per-minute schedule is one row with `missed = 59`, not fifty-nine rows. The count is exact up to ten thousand instants and records ten thousand beyond that — counting a decade of minutes would cost a decade of cron arithmetic, and every scan is bounded. |
| **`CatchUp`** | The missed ticks are claimed and run **oldest first**, one execution each, up to `schedules.max_catch_up` (default 10) per schedule per run; the remainder wait for the next run. Each caught-up execution's `input()` receives *its* tick, so a nightly job catching up three nights processes three nights, not today three times. | One row per tick: the missed instants marked `caught_up`, the present due instant `started`. Ticks beyond what catch-up will ever reach — older than `schedules.catch_up_window` (default 86400 seconds) — are counted and skipped on the first tick this run claims, because a week-long outage should not replay a week of hourly jobs without a person deciding it should; the present due instant is always claimed, however old the gap behind it. |

`Skip` is the default because the catch-up storm is the failure mode that gets noticed: a per-minute job that catches up an hour's outage is sixty executions landing on a queue that was already behind. Catch-up is for work that is *about* its tick — a daily settlement, a monthly statement — and a policy an author reaches for by name.

**A new schedule never backfills.** The first time a schedule is synchronised, `last_tick_at` is set to the instant of synchronisation and no tick before it is considered due: registering a schedule today is a statement about tomorrow. The same rule applies when a schedule is resumed from a pause ([Pausing](#pausing)).

## Overlap

`withoutOverlapping()` asks, at tick time, whether the execution started by this schedule's *previous fired tick* has finished (`isFinished()`: completed, failed, or cancelled). If it has not, the tick is still claimed — the instant passed, and the row says so — but no execution is started: the row is marked `skipped_overlap` and names the execution still running. The next tick asks again. A long-running tick therefore suppresses the ticks under it one by one, each recorded, and the schedule never runs two executions at once.

Without the flag, a slow tick and the next run side by side, as two executions of the same workflow that happen to have started a minute apart. That is correct for independent work and wrong for work that holds a lock or reconciles a ledger, which is why the flag exists and why it is opt-in: skipping is a decision, and the default is to run what was declared.

Overlap is decided from the record, never from a lock: a worker that died mid-execution leaves `running` with a stale claim, and `laraflow:recover` is what resolves that ([execution-lifecycle.md](execution-lifecycle.md#claiming-and-concurrency)). Until it does, an overlapping schedule keeps skipping, each skip recorded against the stuck execution — which is the trail that leads an operator to it.

## Pausing

A schedule may be paused and resumed from the console. Paused, a schedule is synchronised like any other but names no tick; its row records `paused_at`. Resumed, `last_tick_at` jumps to the instant of resumption, so the paused interval is never caught up: *paused* means *do not run*, not *run later*, and an operator who wants the missed work runs it by hand with the start command. A paused schedule still appears in the listing, marked.

## Removal and renaming

A schedule row is never deleted automatically. Unlisting the class (or renaming it) leaves the row standing with no declaration behind it; the listing reports it as **orphaned** and the tick run names it once per run, because a schedule that stopped running is exactly what the health check should surface. A class that is still listed but whose `schedule()` now returns null is a different thing — a declaration that broke — and is reported as that class's `failed` row, by name, not as an orphan. An orphaned schedule names no tick. A `--forget` option on the listing command deletes an orphaned row and its ticks once an operator has decided the history is not wanted; a row with a live declaration cannot be forgotten.

Renaming a workflow class is a new schedule — the identity is the class — and leaves the old one orphaned the same way. The new schedule starts from its synchronisation instant and backfills nothing. Executions the old schedule started are unaffected; they belong to the class recorded on them, and the versioning contract governs what happens when that class changes ([workflow-versioning.md](workflow-versioning.md)).

## Persistence

Two tables, additive, arriving as dated migrations under the freeze and through `tests/Feature/Database/UpgradePathTest.php` as every schema change has since Milestone 6.

**`laraflow_schedules`** — one row per registered schedule, keyed by the workflow class:

| Column | Purpose |
|---|---|
| `id` | |
| `workflow` | The class name, unique. The identity. |
| `cron`, `timezone` | Synchronised from the declaration on every run. |
| `missed_tick_policy` | `skip` or `catch_up`, synchronised. |
| `without_overlapping` | Synchronised. |
| `tenant_id` | Synchronised; recorded on every execution started. |
| `last_tick_at` | The most recent tick instant claimed, or the synchronisation or resumption instant if none has been. Everything before it is not due. |
| `paused_at` | Set while paused. |
| `declared_at` | When the registry last saw a declaration for this row — the sync's own bookkeeping. Orphanhood itself is decided against the live registry: a row whose class the registry no longer lists. |
| `version` | Bumped by every write, for the compare-and-set guards on MySQL and MariaDB, as on the executions table. |
| `created_at`, `updated_at` | |

**`laraflow_schedule_ticks`** — one row per tick claimed:

| Column | Purpose |
|---|---|
| `id` | |
| `schedule_id` | The schedule. **Unique with `tick_at`**: the index that is the once-per-tick guarantee. |
| `tick_at` | The instant, UTC. |
| `outcome` | `started`, `caught_up`, or `skipped_overlap`. |
| `workflow_execution_id` | The execution this tick started, when it started one. Indexed — the origin lookup, the re-dispatch scan, and the overlap check all read it — and deliberately **not** a foreign key: the execution may be deleted by retention long before the tick history is, and a tick that says "I started execution 4812 on the 3rd" is still true after 4812 is gone. |
| `missed`, `missed_from` | How many instants were skipped before this tick, and the earliest; null when none. |
| `overlapped_execution_id` | For `skipped_overlap`: the execution that was still running. |
| `created_at` | When the row was claimed, as distinct from the instant it names. The difference is scheduler lag, which the listing shows. |

`laraflow_schedule_ticks` grows by one row per tick that fires, which for a per-minute schedule is about half a million rows a year. Retention gains a fourth bound, `retention.prune_ticks_after_days` (null, like every other bound: nothing is removed until you say so), applied by `laraflow:prune` to tick rows by their `created_at`, independent of whether the executions they started still exist. Pruning a tick never touches an execution, and pruning an execution never touches a tick.

## Observability

- **Events.** `WorkflowScheduleTicked` after the commit that claimed a tick and started its execution — execution id, workflow (the schedule's identity), correlation and tenant identifiers, the tick instant, the `missed` count, and whether it was caught up — and `WorkflowScheduleSkipped` when a tick was claimed and no execution started (overlap), naming the execution that was still running. Identifiers and instants only, as every event carries; the tick's input is the execution's and is never on an event.
- **The listing**, `laraflow:schedules`, prints every registered schedule with its cron, timezone, policy, next due instant, last tick and its outcome, the lag the last tick was claimed with, and whether it is paused or orphaned; `--pause=`, `--resume=`, and `--forget=` take a class name. **It exits non-zero when any schedule is orphaned or stalled** — a live, unpaused schedule whose most recent due instant is more than two intervals behind, which is a scheduler that stopped running — so the same scheduled health check that runs `laraflow:summary` can run it.
- **`laraflow:summary`** and the dashboard overview gain one attention line for the same stalled condition, so an operator looking at either sees it without knowing to ask. An orphaned row stalls too, eventually, and surfaces through the same line: its ticks have stopped for the same operational purposes, and the listing is where orphan and stalled are told apart.
- **`laraflow:show`** and the dashboard's execution page say what started an execution a tick started — the schedule and the tick instant, with the catch-up and missed counts when they apply — read through the schedule repository's origin lookup by execution id. Nothing on the executions table changes to make this possible; the tick row is the record.
- **The tick run's report** is a table like `laraflow:recover`'s: one row per decision — `started`, `caught up` (one row per caught-up tick), `skipped (overlap)`, `paused`, `not due`, `taken (another scheduler)`, `would start` under `--dry-run`, `failed` with its error on a line of its own — with re-dispatched executions and orphaned rows reported as lines beside the table, and a summary line. Exit code 1 when any schedule is orphaned, any start failed, or an inline `--sync` delivery failed, including under `--dry-run`, which names the ticks it would claim and claims none.
- **The log context** carries nothing new: the execution a tick starts is an ordinary execution from the moment it exists.

## What this is not

- **Not a replacement for Laravel's scheduler.** It rides it. Laraflow decides what is due and makes starting it durable; `schedule:run` is still what wakes it. An application with no scheduler can run the tick command from cron directly, which is the same thing.
- **Not sub-minute, and not jittered.** Cron's granularity is a minute, the scheduler's is a minute, and an expression that asks for seconds is refused rather than approximated.
- **Not dynamic.** Schedules are declared in code and listed in configuration. A table an operator edits to add a schedule at runtime is the authoring question the roadmap defers past 1.0, for the reasons given there ([ROADMAP.md](../ROADMAP.md), *Out of scope through 1.0*); pausing and resuming are the operator's controls, and both act on a schedule the code declared.
- **Not a one-off timer.** "Run this workflow at 15:00 tomorrow" is a delayed dispatch or `Wait::until()` in a first step ([advanced-workflows.md](advanced-workflows.md#delays-schedules-and-wait-states)), not a schedule that fires once.
- **Not per-tenant multiplication.** A schedule starts one execution per tick. Per-tenant work fans out inside that execution, where each tenant's run is a linked child with the identifiers, throttling, and retention it should have.
- **Not exactly-once execution.** It is exactly one *execution* per tick. What that execution's steps do is governed by the at-least-once contract as ever ([execution-lifecycle.md](execution-lifecycle.md)), and `remember()` is still how a side effect is kept to once.

## Compatibility notes

- `Workflow::schedule(): ?Schedule` is a new optional override returning null; an existing workflow is unchanged. `Schedule` and `MissedTickPolicy` are new public classes; the registry and the schedule repository are new public contracts, the latter bound by interface so an application may replace the persistence exactly as it may replace `ExecutionRepository`.
- Two new tables and a new retention bound, each arriving in the step that uses it: `prune_ticks_after_days` under `retention`, and a `schedules` configuration section — `enabled`, `workflows`, `max_catch_up`, `catch_up_window`, `redispatch_after`, `limit`.
- Two new events. Two new commands — `laraflow:schedule`, the tick run, and `laraflow:schedules`, the listing with `--pause`, `--resume`, and `--forget` — and one new attention line in `laraflow:summary` (and the dashboard overview it shares its scan with) for the stalled condition.
- `dragonmantank/cron-expression` becomes a declared dependency ([compatibility.md](compatibility.md)); every Laravel application already has it.
- Nothing on the executions table changes.

## Decided here

The one-way doors, fixed by this document:

1. A schedule is declared on the workflow class and activated by listing the class; the class is the schedule's identity, and a workflow has at most one.
2. A tick is a row, and the unique index on `(schedule, tick_at)` is the once-per-tick guarantee — not a cache lock, not a leader.
3. The tick instant is the cron's most recent instant at or before now, named identically by every scheduler that reads the same minute, stored in UTC.
4. The tick row and the execution it starts commit in one transaction; the dispatch follows the commit; a lost dispatch is healed by the scheduler itself, because a scheduled execution is never one the application is holding.
5. Missed ticks are skipped by default and counted on the row of the tick that ran; catch-up is opt-in, oldest first, bounded per run and by a window, and each caught-up execution is told its own tick.
6. Overlap suppression is opt-in, decided from the record, and every suppressed tick is a row naming what it waited on.
7. Pausing never catches up; a new or resumed schedule never backfills.
8. Tick rows never reference executions by foreign key, so history outlives the executions it describes, and retention prunes the two independently.
9. The executions table gains no column: the tick row is the origin, and the origin is read from it.

## Enforcement

- `tests/Feature/Workflow/ScheduleTest.php` — a due tick claimed once however many runs read the minute, and the losing claim's whole transaction — execution included — rolled back, on every engine; a non-UTC application keeping time end to end; a programmatically registered schedule ticking like a listed one; the same tick on a second run is not due; catch-up oldest first with each execution told its tick; skip counting the gap on one row; the window bounding catch-up; overlap suppression recorded against the running execution; a lost push re-dispatched by the next run and an application's own pending execution left alone; a paused schedule naming no tick and resuming without catch-up; a new schedule backfilling nothing; the correlation id; a refused input rolling the tick back.
- `tests/Feature/Console/ScheduleCommandsTest.php` — the tick report and exit codes; the listing, its pause, resume, and forget; the orphan and stalled conditions; `--dry-run` claiming nothing; the origin line in `laraflow:show`.
- `tests/Feature/Database/SchemaFreezeTest.php` and `UpgradePathTest.php` — the two tables and their indexes, as for every post-freeze migration.
- `.github/actions/install-walk` — three real scheduler processes started together against the engine's database claim one tick and start one execution.
