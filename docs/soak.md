# The soak and benchmark suite

Everything else in this documentation is proven by a test that runs in about a minute. The soak is the one that runs for hours, under load, while things are being broken on purpose — and then asserts that the records still say something coherent.

This document is its contract: what it runs, what it breaks, what it asserts, what it publishes, and what it does not cover. The reasoning behind the shape is [decision 0013](decisions/0013-what-the-soak-proves.md).

## What the soak is

A load generator, a fault injector, and a set of invariants, run against a real database and real queue workers on a schedule, on every engine the compatibility policy names.

It is **not** part of `composer test`. The suite must stay fast and deterministic; the soak is neither. It lives in `workbench/` — dev-only, `export-ignore`d, never installed into an application — behind `soak:*` console commands, and is driven in CI by its own scheduled workflow.

It is **not** a production deployment, and running clean is not production mileage. [limitations.md](limitations.md) keeps saying there is none, and 1.0 does not change that.

## Where it runs

A scheduled GitHub Actions workflow, one job per engine cell — MySQL 8.4, MariaDB 10.11, MariaDB 11.4, PostgreSQL 14, PostgreSQL 17, SQL Server 2022 — the same six the test matrix uses, through the same `LARAFLOW_TEST_DB_*` seam. The engines are where this project has found its defects in every milestone since 6; soaking only the fastest one would soak the one least likely to teach anything.

Each job runs real `queue:work` processes against a real queue connection and a real `laraflow:schedule` cron loop — not `sync`, not fakes. A soak against fakes proves the fakes.

## The workload

Mixed, because the interesting failures are at the seams between features, not inside one:

- Plain linear workflows, the baseline.
- Steps with retry policies that fail and recover.
- Timed waits and signal waits, the signals arriving from a separate process — some before the step parks, exercising the signal inbox.
- Sub-workflows, nested to several levels.
- Fan-out steps at width, joining.
- Compensating steps on workflows driven to terminal failure.
- Scheduled workflows with several schedulers racing the same ticks.
- Two tenants under concurrency and rate ceilings, one flooding.
- Connector calls against a local stub server, including error statuses and `Retry-After`.
- A retention pass running concurrently with all of it.

## The faults

Injected continuously while the workload runs, not in a tidy sequence afterwards:

| Fault | How | What it exercises |
|---|---|---|
| Worker loss | `SIGKILL` a `queue:work` process mid-step | Stale-claim takeover, at-least-once recovery, the `fruitless_claims` bound |
| Queue loss | Stop and restart the queue backend | Dispatches that fail after a commit, `laraflow:recover` as the backstop, duplicate delivery on return |
| Database loss | Stop and restart the engine container | Claims that cannot be written, checkpoints failing mid-transaction, reconnection and recovery |
| Clock skew | Schedulers started with offset clocks | The once-per-tick guarantee when claimants disagree about now |

**What the database fault is not.** Stopping a container is connection loss and recovery. It is **not** a promoted replica, a split brain, or replication lag — no replica set is stood up, and none of those is covered. Saying otherwise would be the kind of claim this project does not make ([decision 0013](decisions/0013-what-the-soak-proves.md)).

## The invariants

The deliverable. After each round, with the load stopped and recovery allowed to settle, every one of these must hold. A violation is a release blocker, triaged like any other defect and fixed with the regression test that would have caught it.

1. **No execution rests in a non-terminal state past its bounds.** Nothing `running` with a claim older than `laraflow.execution.stale_after` and no recovery scheduled; nothing `retrying` past its `next_retry_at` with recovery idle; nothing `waiting` past a deadline that has passed.
2. **No two attempt rows for one logical attempt.** The unique `(step_execution_id, attempt)` index says a duplicate delivery cannot double-count; the soak checks the counts agree with the attempts actually made.
3. **No fan-out that both completed and failed.** A group's recorded outcome matches its members' terminal states, and every member belongs to exactly one group.
4. **Exactly one execution per `(schedule, instant)`.** However many schedulers raced, however skewed their clocks.
5. **No remembered key settled twice,** and no `settled` record whose value disagrees with the work's recorded outcome.
6. **No orphaned rows.** No step rows, attempt rows, idempotency rows, or inbox rows whose execution is gone; no execution whose plan rows are missing.
7. **Every finished execution's persisted plan matches the definition it ran,** at the version it recorded.
8. **Every terminal execution has a `finished_at`,** and no non-terminal one does.
9. **No credential, input, output, or context value appears in any log line** the run produced.

Invariants 1–8 are checked by querying the database the harness just hammered. Invariant 9 is checked by scanning the captured worker logs for the sentinel values the workload seeded.

## The benchmark

Measured on the same runs, published rather than gated:

- Executions completed per minute, per engine, at a fixed worker count.
- Claim contention: the proportion of claims refused because another worker held one.
- Checkpoint latency, p50 and p95.
- Retention cost per 100,000 rows, by tier.
- The metrics snapshot's wall time at a seeded table size.

Numbers are committed as a baseline and charted over time. A significant move is **a question to answer, not a red build** — shared CI runners vary by more than the regressions worth catching, and a flaky gate is a gate everyone learns to re-run until it is green ([decision 0013](decisions/0013-what-the-soak-proves.md)).

## What gates 1.0

Fourteen consecutive scheduled runs clean across all six engine cells, plus one multi-hour sustained run per engine. Not a calendar window — the reasoning is in [decision 0013](decisions/0013-what-the-soak-proves.md), and the roadmap's exit criterion is amended to match ([ROADMAP.md](../ROADMAP.md)).

The soak does not stop at 1.0. Rare defects are found by runs, and the runs keep coming afterwards.

## When it fails

A failed soak run is a defect report with an unusually good reproduction: the workload, the faults, and the database are all still there. The procedure is the one every milestone has used — reproduce it in the fast suite as the regression test that would have caught it, fix it, and keep both.

A run that fails to *start* — an image that moved, a broker that never came up — is infrastructure, fixed in the workflow, and does not count toward the fourteen.
