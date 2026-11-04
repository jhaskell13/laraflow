# Upgrading, rolling back, and recovering

This document is the operational contract for moving an application between Laraflow versions. It covers what the schema freeze guarantees, the procedure for an ordinary upgrade, how to roll one back, and how to recover work that was in flight while you did it.

The guarantees here start at the schema freeze (Milestone 6). Before it, migrations were edited in place and the only supported answer to a schema change was to reinstall the tables. That is over.

## What the freeze guarantees

**The shipped migrations are immutable.** The three dated migrations that create `laraflow_workflow_executions`, `laraflow_step_executions`, and `laraflow_step_attempts` will not change again, and neither will any migration shipped after them — Milestone 7 added four, all additive: `fruitless_claims` on executions, `wait_signal` on steps, the sub-workflow parent-linkage columns, and the scrub-scan index; Milestone 8 added a fifth, the pending-scan index; a sixth, `plan_version` on executions; and a seventh, the `laraflow_idempotency_keys` table; Milestone 9 an eighth, an index on `parent_step_execution_id` for fan-out joins, and a ninth, `compensates_step_execution_id` on step rows (with its index) for compensation; Milestone 10 a tenth and eleventh, the `laraflow_schedules` and `laraflow_schedule_ticks` tables for scheduled workflows ([schedules.md](schedules.md)) — new tables, touching nothing existing — and a twelfth, `deferred_until` and `deferrals` on executions with two indexes for the tenant ceilings ([tenancy.md](tenancy.md)); the indexes build on a populated table, so the online-index note below applies. The v0.6.0 audit added a thirteenth, the `laraflow_signal_inbox` table — new, touching nothing existing — and a fourteenth, which converts data rather than adding to the schema and is the one migration so far that must not run against live workers in some applications — see [The UTC timestamp migration](#the-utc-timestamp-migration). Any later schema change arrives as a *new* dated migration.

Concretely, for an installed application:

- `php artisan migrate` is always enough. You will never be told to drop and recreate Laraflow's tables to pick up a change.
- Running `migrate` when nothing changed is a no-op, not an error.
- A schema change can be rolled back with `migrate:rollback` without losing the install or the rows in it.

This is enforced, not promised: `tests/Feature/Database/SchemaFreezeTest.php` fails if a shipped migration's resulting schema changes at all, and `tests/Feature/Database/UpgradePathTest.php` applies a new migration to an already-populated database and asserts the data survives both the upgrade and its rollback.

Every schema change is called out in `CHANGELOG.md` under the release that introduced it, with its operational impact.

## Upgrading

Laraflow is a library: its version moves when you ask Composer to move it.

```bash
composer update laraflow/laraflow
php artisan migrate
```

Before a **minor or major** bump, read the changelog entries between your version and the new one. Look for three things: schema changes (they tell you what `migrate` will do), behaviour marked **Breaking**, and anything listed under *Deprecated*.

### Upgrading with a schema change, without downtime

Laraflow's own migrations are written to be additive — new nullable columns, new indexes, new tables — so the usual sequence is safe while workers are running:

1. **Deploy the new code to a worker-free host first**, or put the new code in place without restarting workers. Old workers keep running the old code against the old schema.
2. **Run `php artisan migrate`.** Additive changes do not disturb rows that the running workers hold claims on. An *index* build is the exception to "online": PostgreSQL builds it under a lock that blocks writers for the duration (the migration runs inside a transaction, where `CONCURRENTLY` is unavailable), SQL Server likewise outside Enterprise's `ONLINE = ON`, and MySQL before 8.0.29 — below the supported floor, but still met in the field — rebuilds the table for a column added with `AFTER`. On a large table, run such a migration in a quiet window; the changelog names every migration that adds an index.
3. **Restart workers** so they pick up the new code. Executions claimed by a worker that stops mid-run are not lost; see [Recovering work](#recovering-work-interrupted-by-an-upgrade).

If a release ever needs a migration that is *not* safe to run against live workers, its changelog entry will say so explicitly and give the sequence. Drain the queue first in that case: stop dispatching, let `php artisan queue:work --stop-when-empty` finish, then migrate.

### The UTC timestamp migration

`2026_11_04_000002_store_laraflow_timestamps_in_utc` moves every Laraflow timestamp to UTC ([compatibility.md](compatibility.md#stored-timestamps)). What it does depends on your configuration:

- **`app.timezone` is UTC** (Laravel's default): stored values already are UTC, and no row is touched. On MySQL and MariaDB the `timestamp` columns become `datetime` — an `ALTER TABLE` per Laraflow table, which rebuilds it; run it in a quiet window on a large table. On PostgreSQL, SQL Server, and SQLite the migration does nothing. Safe with workers running.
- **`app.timezone` is anything else**: every non-null timestamp is rewritten from `app.timezone` wall-clock to UTC, in PHP, in chunks keyed by id, inside one transaction. **Stop workers and the scheduler first** — a row a new-code worker writes during the conversion is already UTC and would be shifted a second time; an old-code worker writing after it would write wall-clock again. The sequence: stop dispatching and the `laraflow:schedule` cron, let `php artisan queue:work --stop-when-empty` drain, deploy, `php artisan migrate`, restart workers and the scheduler. Its time grows with the number of rows; prune first ([retention.md](retention.md)) if history is large.

The migration reads `app.timezone` when it runs: it must be the zone the rows were written in. A value inside the repeated autumn hour of that zone is ambiguous by construction and is read as its first (daylight-time) occurrence. A database connection with its own `timezone` setting on MySQL or MariaDB changes only how `timestamp` columns were rendered before the retype, which the migration preserves.

Rolling it back (`migrate:rollback --step=1`) is the exact reverse under the same rules — workers stopped in a non-UTC application — and on MySQL and MariaDB refuses any value after 2038-01-19, which a `timestamp` cannot hold. The previous release's code reads UTC columns as `app.timezone` wall-clock, so roll the migration back before rolling the code back, not after.

### Checking before you commit to it

On a copy of production data:

```bash
php artisan migrate --pretend     # the SQL, without running it
php artisan migrate               # on the copy
php artisan laraflow:summary      # the executions are all still there
php artisan laraflow:connectors --check
```

`laraflow:connectors --check` resolves every connector and exits non-zero if one cannot be built, so it belongs in the deploy pipeline whether or not the schema moved.

## Upgrading to 0.6

0.6.0 ships five post-freeze migrations, and for most applications `composer update` and `php artisan migrate` are still the whole upgrade. Read this section first if you run a non-UTC `app.timezone`, a large executions table, a custom `ExecutionRepository`, or code that extends Laraflow's classes. The full list is the [0.6.0 changelog](../CHANGELOG.md).

**The migrations.**

| Migration | What it does | Against live workers |
|---|---|---|
| `2026_11_03_000000_create_laraflow_schedules_table` | New table | Safe |
| `2026_11_03_000001_create_laraflow_schedule_ticks_table` | New table | Safe |
| `2026_11_04_000000_add_tenant_ceilings_to_laraflow_workflow_executions` | `deferred_until` and `deferrals` on executions, and two indexes **built on the populated table** | Safe, but run it in a quiet window on a large table ([the index note](#upgrading-with-a-schema-change-without-downtime)) |
| `2026_11_04_000001_create_laraflow_signal_inbox_table` | New table | Safe |
| `2026_11_04_000002_store_laraflow_timestamps_in_utc` | Converts every stored timestamp to UTC; retypes `timestamp` to `datetime` on MySQL and MariaDB, rebuilding each table | Safe when `app.timezone` is UTC (quiet window on MySQL/MariaDB); **stop workers and the scheduler otherwise** — see [The UTC timestamp migration](#the-utc-timestamp-migration) |

**Breaking changes to check for.**

- **A custom `ExecutionRepository`** has new obligations: `claim()` consults tenant ceilings (only for a row it could take) and takes a trailing `bool $reexecuteFailed = false`, refusing a `failed` execution without it; `park()`, `childOfStep()`, `createMember()`, and `deferred()` must exist; `recordSignal()` buffers a signal for an unfinished execution not yet parked on the name instead of refusing it, and returns a `SignalReceipt`; the four recovery scans take a trailing `?DateTimeImmutable $availableBefore = null`. It must also read and write Laraflow's columns as UTC. The contract is in [public-api.md](public-api.md#persistence); code that only reads should type-hint `ExecutionReader` instead.
- **Re-running a failed execution is an opt-in.** An application that re-dispatched failures with `WorkflowDispatcher::dispatchExecution()` must pass `reexecuteFailed: true`; `laraflow:execute`, the dashboard, and `Handoff` already do.
- **Constructor collaborators**, for anything built by hand rather than resolved from the container: `WorkflowRunner` takes an `ExecutionScope` where it took a `LoggingContext`, and `TransportPipeline` and `ThrottlingTransport` take it too; `EloquentExecutionRepository` takes `TenantCeilings`; `RetentionService` and `AttentionScan` take the `ScheduleRepository`.
- **Public concrete classes are `final`** unless designed for extension. Code that subclassed one should implement or decorate the documented interface and bind it in the container.
- **The generators are `laraflow:make-workflow`, `laraflow:make-step`, and `laraflow:make-connector`.** `make:workflow`, `make:step`, and `make:connector` still work as deprecated aliases that print a warning; update scripts and documentation before a future minor removes them.
- **Trigger routes are throttled by default.** `laraflow.triggers.middleware` defaults to `['api', 'throttle:60,1']`, and a body over `laraflow.triggers.max_body_bytes` (65536) is refused with `413`. An application that published its config keeps its own middleware list — add a throttle to it.
- **Bulk `laraflow:cancel` and `laraflow:execute` select only what they can act on.** Completed and cancelled executions are never selected, `matched` counts the actionable set, a `--status` naming only those is refused, and the `--json` handoff outcomes are `push_failed` and `step_failed`. A script reading the old shapes should be checked against [inspection-and-observability.md](inspection-and-observability.md#machine-readable-output).

## Rolling back

### Rolling back a schema change

`migrate:rollback` undoes the last batch. Because Laraflow's migrations are additive, this drops what the upgrade added and leaves the install and its rows intact:

```bash
php artisan migrate:rollback --step=1
composer require laraflow/laraflow:<previous-version>
```

Roll the code back **with or after** the schema, not before: older code does not know about a newer column, but newer code may require one.

A rollback that crosses a release which *removed* something is the case to be careful with — a `down()` can recreate a column but cannot recreate the data that was in it. Changelog entries for destructive migrations say so.

### Rolling back the application only

If the schema did not move between the two versions — the common case — rolling back is just Composer:

```bash
composer require laraflow/laraflow:<previous-version>
```

Nothing else is needed. Executions persisted by the newer code remain readable, because the schema they were written against is the same one.

## Recovering work interrupted by an upgrade

Restarting workers mid-execution is ordinary, expected, and already covered by the durability contract: a worker that stops holds a claim that ages out, and the execution resumes from its last checkpoint. The full semantics are in [execution-lifecycle.md](execution-lifecycle.md) and [queue-and-recovery.md](queue-and-recovery.md); what matters for an upgrade:

```bash
# What is in flight, and what is stuck
php artisan laraflow:summary

# Re-dispatch work whose worker disappeared, under explicit bounds
php artisan laraflow:recover --dry-run
php artisan laraflow:recover
```

`laraflow:recover` touches interrupted work — executions claimed by a worker that is gone — and delivers scheduled work that has come due: retries, waits, recorded signals, and sub-workflow heartbeats. It does not retry genuinely failed executions; those answer to their step's retry policy, or to `laraflow:execute <id>` if you want to re-run one deliberately.

Three points that matter during a deploy:

- **Steps may run more than once.** Laraflow is at-least-once at recovery boundaries. If a step has an external side effect, wrap it in `$context->remember()` — the contract is [idempotency.md](idempotency.md) — and an upgrade is exactly when that gets exercised.
- **A stale claim is not a failure.** Give a restarted worker its claim timeout (`laraflow.execution.stale_after`) before deciding something is stuck.
- **A deploy that changes a workflow's `steps()` strands its in-flight executions unless you version the plan.** The persisted plan is validated against the code on every claim, so an execution created before the deploy and claimed after it fails with `WorkflowDefinitionMismatchException` — the refusal is deliberate and silence means refuse. Bump `version()` and declare the matching entry in `planMigrations()` and those executions are carried forward instead, under the existing claim, before the first step write. A migration may rewrite only the part of the plan the execution has not yet reached. The contract, and what is refused and why, is [workflow-versioning.md](workflow-versioning.md). Plan this before the deploy, not after it: an execution already stranded is recovered by rolling the code back or by shipping the migration.

## If an upgrade goes wrong

1. **Stop dispatching** new executions. Leave the workers alone for now — in-flight work is checkpointed.
2. **Look before acting:** `php artisan laraflow:summary`, then `laraflow:show <id>` on anything that looks wrong. Both read the public query contract, so neither depends on schema internals.
3. **Roll back** the schema and the code, in that order, per [Rolling back](#rolling-back).
4. **Recover** what was interrupted: `php artisan laraflow:recover --dry-run` first, then without it.
5. Nothing above deletes execution history. Retention is the only thing that removes rows, it is off by default, and it never scrubs or deletes a `failed` execution under the general bound — see [retention.md](retention.md).

## Version support

Which versions receive fixes, how long a deprecation lasts before removal, and the policy for dropping PHP, Laravel, or database versions are in [compatibility.md](compatibility.md). Security fixes and their support window are in [SECURITY.md](../SECURITY.md).
