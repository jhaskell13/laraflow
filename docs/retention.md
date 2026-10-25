# Retention

This document is the contract for how Laraflow removes finished executions, in what form, and under what guarantees. It builds on [docs/execution-lifecycle.md](execution-lifecycle.md) — in particular `finished_at`, the single terminal timestamp retention reads — and on the status semantics in [docs/retry-and-failure-policy.md](retry-and-failure-policy.md).

Everything stated here is exercised by `tests/Feature/Workflow/RetentionServiceTest.php`, `tests/Unit/Workflow/RetentionPolicyTest.php`, and `tests/Feature/Console/PruneCommandTest.php`. If the code and this document disagree, one of them has a bug.

## Nothing is removed until you say so

Every retention bound is **null by default**, and Laraflow never prunes on its own schedule. Retention is irreversible, and a package that quietly began deleting an application's execution history on upgrade would be indefensible. You set the bounds in `laraflow.retention.*` and you schedule the command:

```php
// routes/console.php
Schedule::command('laraflow:prune --force')->daily()->withoutOverlapping();
```

`--force` is there because the command deletes durable records and so uses Laravel's standard production confirmation — the same one `migrate` and `db:wipe` carry. A dry run changes nothing and never asks.

If you schedule the command without configuring any bound, it **exits non-zero** and says so. A scheduled prune that silently does nothing is indistinguishable from one that is working, right up until someone looks at the size of the attempt table.

## What "old" means

Every bound counts from **`finished_at`** — the moment the execution stopped — not from creation. A long-running execution is never pruned for having *started* a long time ago.

Only finished work is eligible at all. `pending`, `running`, and `retrying` executions are invisible to every retention scan and every retention write. A retry waiting out its backoff is not old work; it is future work.

| Status | Finished? | Why it matters here |
|---|---|---|
| `completed` | Yes | Done. Eligible for every tier. |
| `cancelled` | Yes | Terminal. Eligible for every tier. |
| `failed` | Yes | **Finished but re-executable.** `laraflow:execute` on a failed execution is the documented recovery path, so retention treats it with care — see below. |
| `pending`, `running`, `retrying` | No | Never touched. |

## The three tiers

| Tier | Config key | Applies to | Removes | Keeps |
|---|---|---|---|---|
| **Scrub** | `scrub_after_days` | `completed`, `cancelled` | Input, context snapshot, every step's output | The execution and step rows — what ran, when, how it ended, every error |
| **Prune attempts** | `prune_attempts_after_days` | `completed`, `cancelled`, `failed` | The append-only attempt history | The execution and step rows, including the step's latest status, error, and attempt counter |
| **Delete** | `delete_after_days` | `completed`, `cancelled` | Everything | Nothing |
| **Delete failed** | `delete_failed_after_days` | `failed` | Everything | Nothing |

A typical policy, from most to least aggressive on the thing that grows:

```php
'retention' => [
    'prune_attempts_after_days' => 14,   // history is diagnostics; two weeks is plenty
    'scrub_after_days' => 30,            // payloads may carry personal data
    'delete_after_days' => 365,          // the audit trail, kept a year
    'delete_failed_after_days' => 90,    // unrecovered failures, kept a quarter
],
```

### Scrub never touches failed executions, and there is no knob to make it

Re-executing a failed execution needs exactly two things: its input and its context. Scrubbing destroys both. The failure mode would be silent and distant — a workflow re-run weeks later against an empty context, producing nonsense or throwing somewhere unrelated, with nothing connecting that to a retention setting changed a month earlier. No configuration should make that possible. If you want failed executions gone, delete them, explicitly, with `delete_failed_after_days`.

### Prune attempts does touch failed executions — here is the trade-off

Attempt pruning covers `failed` executions because resumability genuinely does not depend on the history table. The retry budget reads the step row's `attempts` counter, which pruning never touches; the step row also keeps the *latest* attempt's status, error, and timestamps. A pruned failed execution re-executes exactly as an unpruned one would.

**What you do lose** is the per-attempt diagnostics of older attempts: that attempt 1 timed out while attempt 2 was a connection refusal, with each one's duration. If you investigate *why* an old failed execution failed after its history is pruned, you have the last attempt's error and nothing earlier. For most installations that is the right trade — the history table is the one that grows without bound, and a failed execution older than the bound is one nobody has investigated in that long. If your operations depend on the full attempt record of unrecovered failures, set `prune_attempts_after_days` no shorter than `delete_failed_after_days`, so the history outlives the decision to delete.

### Delete failed is a separate bound on purpose

A `failed` execution is work that could still be recovered. Discarding it should be a decision an operator took, not a side effect of configuring general retention — so `delete_after_days` never reaches it, and `delete_failed_after_days` reaches nothing else.

## Guarantees

Three properties hold across every tier, each pinned by tests.

**The guard is re-applied at every write.** A scan reads candidates; then the write re-checks `status` and `finished_at` *in the same statement*. Claiming an execution sets `status = running` and clears `finished_at`, so an execution a worker reclaimed between the scan and the write matches zero rows and is silently spared. This is the same compare-and-set discipline claiming and cancellation already use, for the same reason: a `failed` execution is re-executable, so retention is always racing a possible recovery. The command reports spared executions as *skipped* — not as failures, because the guard refusing to act is the guard doing its job.

**Deletion leaves no orphans, with or without database cascades.** The schema declares cascading foreign keys, but SQLite only enforces them when the connection asks, and an application may not have. Children are removed by asking "which step and attempt rows no longer have a parent?" rather than "which belong to the ids we asked about?" — so a parent the guard spared keeps its children, and a parent that went takes its children with it, on every engine. A test runs the whole path under `PRAGMA foreign_keys = OFF`.

**Every tier is bounded and self-limiting.** At most `limit` executions per tier per run, written in `chunk`-sized batches. And an execution a tier has already handled stops matching that tier's scan — delete removes the row, scrub requires `payloads_scrubbed_at IS NULL`, and attempt pruning requires that attempt rows still exist. Without this a scheduled run would spend its whole limit rescanning the oldest executions every night and never reach anything newer.

Two smaller ones:

- **Tiers run delete → prune → scrub**, so the gentler tiers never do work on rows about to disappear.
- **A dry run predicts the run that follows it.** A dry run deletes nothing, so the gentler tiers would otherwise still see rows a real run would have destroyed and over-report. The would-be-deleted ids are carried forward, and a test asserts the dry-run and real-run reports agree field by field. The one number a dry run does not give is attempt *rows*: counting them would mean reading the history the run is not touching, and an estimate an operator might act on is worse than an honest absence.

## The command

```
laraflow:prune
    --scrub-after=            override laraflow.retention.scrub_after_days
    --prune-attempts-after=   override laraflow.retention.prune_attempts_after_days
    --delete-after=           override laraflow.retention.delete_after_days
    --delete-failed-after=    override laraflow.retention.delete_failed_after_days
    --limit=                  executions per tier per run        (default 1000)
    --chunk=                  executions per write               (default 100)
    --workflow=               only this workflow class
    --tenant=                 only this tenant
    --dry-run                 report; change nothing; never asks
    --force                   skip the production confirmation
```

Every option overrides the configured value for one invocation, which is how one workflow or one tenant is pruned on a different schedule from everything else:

```bash
php artisan laraflow:prune --dry-run                                  # what would the configured policy do?
php artisan laraflow:prune --workflow='App\Workflows\Import' --delete-after=7 --force
php artisan laraflow:prune --tenant=acme --scrub-after=1 --force      # a tenant's data-deletion request
```

The report lists only the tiers you configured — a tier with no bound is not a tier that did nothing — and a completed run logs its counts, because deletions an operator cannot later account for are exactly the ones they will want to account for. Dry runs and runs that did nothing are not logged.

### Warnings

Some configurations are valid but almost certainly typos. They are reported before the run, not rejected:

| Configuration | Warning |
|---|---|
| `delete_after_days` ≤ `scrub_after_days` | Completed work is deleted at or before the point it would have been scrubbed, so scrubbing never runs. |
| `delete_after_days` ≤ `prune_attempts_after_days` | Completed work is deleted before its history would be pruned. |
| `delete_failed_after_days` < `delete_after_days` | Recoverable failed work is discarded sooner than completed work. |

### Validation

A bound below one day is rejected: zero would act on work that finished seconds ago. A blank environment value means "disabled", consistent with every other key — a stray `LARAFLOW_RETENTION_DELETE_AFTER_DAYS=` in `.env` must neither be rejected nor mean "delete everything".

## Archiving before deletion

Laraflow does not archive; it deletes. If you need the records elsewhere first, the listing API gives you exactly the set a prune would act on:

```php
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;

$filter = new ExecutionFilter(
    status: [ExecutionStatus::Completed, ExecutionStatus::Cancelled],
    finishedBefore: now()->subDays(365)->toImmutable(),   // the same cutoff delete_after_days = 365 would use
    oldestFirst: true,
);

$cursor = null;

do {
    $page = $repository->list($filter, limit: 500, cursor: $cursor);

    foreach ($page as $execution) {
        $archive->store($execution, $repository->steps($execution), $repository->attemptHistory($execution));
    }

    $cursor = $page->nextCursor;
} while ($page->hasMore());
```

Run that from a scheduled job that completes before the prune runs. Because both read `finished_at` and both windows are half-open, an execution that finishes between the two is picked up by neither tonight and by both tomorrow.

## Capacity planning

- **The attempt table is the one that grows.** Every finished attempt is one row, so growth is `executions × steps × attempts`. With retries enabled it is the first table to prune.
- **Scrubbing reclaims payload space without losing the audit trail.** If your inputs or contexts are large, scrub well before you delete.
- **Each run is bounded by `limit × tiers`.** On a backlog, the first runs will hit the limit; the oldest work goes first, so each run makes progress at the end of history that matters. Raise `--limit` for a one-off catch-up, then leave the schedule to keep pace.
- **Every retention scan is indexed** — `(status, finished_at)` for the window, the attempts table's foreign-key index for the "still has history" check — so a run's cost scales with what it removes, not with the size of the table.

## Operational checklist

- Set at least `prune_attempts_after_days` if retries are enabled; it is the bound that keeps the database from growing without limit.
- Schedule `laraflow:prune --force` with `withoutOverlapping()`.
- Run `laraflow:prune --dry-run` after changing any bound, and read the warnings.
- Decide deliberately about `delete_failed_after_days`; the default leaves recoverable work alone forever.
- If you archive, archive on the same `finished_at` cutoffs and schedule it to finish first.
