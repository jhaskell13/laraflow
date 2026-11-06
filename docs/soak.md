# The soak and benchmark suite

Everything else in this documentation is proven by a test that runs in about a minute. The soak is the one that runs for hours, under load, while things are being broken on purpose — and then asserts that the records still say something coherent.

This document is its contract: what it runs, what it breaks, what it asserts, what it publishes, and what it does not cover. The reasoning behind the shape is [decision 0013](decisions/0013-what-the-soak-proves.md).

## What the soak is

A load generator, a fault injector, and a set of invariants, run against a real database and real queue workers on a schedule, on every engine the compatibility policy names.

It is **not** part of `composer test`. The suite must stay fast and deterministic; the soak is neither. It lives in `workbench/` — dev-only, `export-ignore`d, never installed into an application — as a family of `soak:*` console commands under one orchestrator, `soak:run`, and is driven in CI by its own scheduled workflow, `Soak`.

It is **not** a production deployment, and running clean is not production mileage. [limitations.md](limitations.md) keeps saying there is none, and 1.0 does not change that.

## Where it runs

A scheduled GitHub Actions workflow, nightly, one job per engine cell — MySQL 8.4, MariaDB 10.11, MariaDB 11.4, PostgreSQL 14, PostgreSQL 17, SQL Server 2022 — the same six the test matrix uses, through the same `LARAFLOW_TEST_DB_*` seam. The engines are where this project has found its defects in every milestone since 6; soaking only the fastest one would soak the one least likely to teach anything.

Each job runs real `queue:work` processes against a real Redis queue — not `sync`, not fakes; a soak against fakes proves the fakes — and three scheduler processes against a real `laraflow:schedule`. The queue backend is deliberately a separate service from the database, so that losing one is not losing the other.

Locally, `composer soak` runs the same orchestrator against SQLite with the `database` queue, as a smoke run: a few minutes, worker kills only, because there is no container to stop. SQLite is not an engine the soak proves; it is where a change to the soak itself is tried first.

## The workload

Mixed, because the interesting failures are at the seams between features, not inside one:

- Plain linear workflows, with a conditional step that is durably skipped for half of them. These are also the executions retention is allowed to remove during the run.
- Steps with retry policies that fail once or twice and heal; a few that fail past their budget on purpose.
- A step with a deadline that, for some executions, deliberately overruns it on its first attempt.
- Side effects inside `remember()`, whose recorded value the verifier later compares to the step's output.
- Timed waits, and signal waits that a separate process approves — late, or early so the signal inbox keeps them, or never, so the wait's own timeout fires.
- Sub-workflows nested up to three deep, the bottom one skipped by a conditional sub-workflow step.
- Fan-outs of two to twelve members, every third of which flakes once, joined under a retry policy.
- Sagas: reserve, charge, ship — a share of them doomed, so the charge and the reservation are compensated in reverse, with the undo itself failing once for some.
- Connector calls against a stub provider that answers with success, `429` with `Retry-After`, `5xx`, or `404` by weight, plus a paginated listing — through the same transport retries, rate limit, and error mapping an application's connector uses.
- Two scheduled workflows, one every minute and one every minute without overlapping, claimed by three schedulers whose clocks disagree.
- Two tenants, one of them held to a concurrency and a rate ceiling and flooded every half minute.
- Cancellations of a few percent of whatever is in flight.
- A twentieth of executions run inline in the load generator's own process, contending with the workers.
- Probes: reserved-prefix signal names, in two casings, sent through the public entry and expected to be refused every time.
- Retention, all three tiers, running every fifteen seconds against the linear workflow's finished executions while workers are still finishing more.

Every payload the load generator writes carries a sentinel, and the connector's credential is one too; the leak scan at the end looks for both.

## The faults

Injected continuously while the workload runs, not in a tidy sequence afterwards:

| Fault | How | What it exercises |
|---|---|---|
| Worker loss | `SIGKILL` a `queue:work` process every fifteen seconds or so, jittered; a replacement is started | Stale-claim takeover, at-least-once recovery, interrupted and timed-out attempts, the queue's own redelivery of the reserved job meeting the claim |
| Queue loss | Stop the Redis container for a dozen seconds, then start it | Pushes that fail after a commit, the pending scan finding never-dispatched executions, duplicate delivery on return |
| Database loss | Stop the engine's container for a dozen seconds, then start it | Claims that cannot be written, checkpoints failing mid-transaction, reconnection, and recovery of everything that was in flight |
| Clock skew | Schedulers run with clocks 25 and 50 seconds ahead of and behind the true time | The once-per-tick guarantee when claimants disagree about what minute it is |

**What the database fault is not.** Stopping a container is connection loss and recovery. It is **not** a promoted replica, a split brain, or replication lag — no replica set is stood up, and none of those is covered. Saying otherwise would be the kind of claim this project does not make ([decision 0013](decisions/0013-what-the-soak-proves.md)).

**Two settings are deliberately not production-like.** `laraflow.execution.stale_after` is forty-five seconds rather than minutes, with the job timeout and the queue's `retry_after` ordered beneath it as [queue-and-recovery.md](queue-and-recovery.md#under-horizon) asks, so a killed worker's claim is reclaimed within the run; and `laraflow.recovery.abandon_after_attempts` is lifted far above its default of three, because the fault injector interrupts some executions many times and abandonment — parking an execution for an operator after repeated interruption — would otherwise be the designed outcome for them and nothing could settle. Abandonment is exercised by the test suite; the soak exercises what happens when recovery is allowed to keep going. `laraflow.recovery.pending_after` is on, so a dispatch lost to a queue outage is found.

## The invariants

The deliverable. After each run, with the load stopped and recovery allowed to settle, every one of these must hold. A violation is a release blocker, triaged like any other defect and fixed with the regression test that would have caught it.

1. **No execution rests in a non-terminal state.** After the load stops, the schedulers and retention are stopped, every waiting approval is drained, and recovery keeps running; the run must reach a point where nothing is `pending`, `running`, `retrying`, `waiting`, or `compensating` — and stay there for two consecutive polls, so a count that touches zero while a worker is mid-step does not end the run early.

   Settling stops on **progress, not on a clock**. A backlog can take many minutes to drain — a killed worker's job is invisible until the queue's `retry_after` hands it back — and failing a run that is still draining healthily would be the flaky gate [decision 0013](decisions/0013-what-the-soak-proves.md) declines to build. The run gives up only when the unfinished count has not fallen for `--stall-after` seconds, or when the hard `--settle-timeout` ceiling is reached. Either of those is a finding; a slow drain is not.
2. **Attempt rows for one step are numbered 1..n** with no gap and no duplicate, so a duplicate delivery never double-counted; and the step row's attempt counter equals its rows — except for a step that is parked or running, which has an attempt under way and no row for it yet, because a row is written when an attempt *ends*. On a settled run those are the steps of cancelled executions.
3. **No fan-out that both completed and failed.** A completed fan-out step has every declared member completed and says so in its output; a failed one has a member that did not; no member is unfinished once the step is decided.
4. **Exactly one execution per `(schedule, instant)`** — read from the executions' own recorded tick, independently of the ticks table that is supposed to guarantee it — and the ticks table agrees: unique per instant, each row naming what it did.
5. **No remembered key settled twice**, none left pending on an execution that completed, and every settled charge equals the output of the step that made it.
6. **No orphaned rows.** No step, attempt, idempotency, or inbox row whose execution is gone; no child whose parent is gone; no execution with no plan rows.
7. **Every execution's persisted plan matches the definition it ran.**
8. **Every terminal execution has a `finished_at`,** and no non-terminal one does.
9. **No credential, input, output, or context value appears in any log line Laraflow wrote** — its lifecycle log, its own warnings and errors — checked by scanning every captured process log for the sentinels the run seeded. The stub provider's own log is excluded: it legitimately sees the token on every request.

   The same scan runs over every *other* line on those streams — Laravel's exception handler rendering what a step threw, the queue worker's own output — and is reported **informationally** rather than as a failure. Laraflow does not write those lines and cannot redact them, and the boundary is already recorded in [SECURITY.md](../SECURITY.md) and the [threat model](threat-model.md); but what an operator's log actually contains is worth knowing, and a number that climbs is worth asking about.
10. **Every reserved-prefix signal probe was refused.** The threat model's third invariant, held under load.

Invariants 1–8 are checked by querying the database the harness just hammered, directly rather than through the repository: a check that trusts the thing it checks is not a check. Invariants 9 and 10 read the captured process logs and the signaller's own record of what it probed. The ten are thirteen checks in the report, because two of them are each a pair and the application-log scan is its own line. `soak:verify` runs all of them on their own, against whatever database the soak connection names.

## The benchmark

Measured on the same runs, published rather than gated:

- Executions finished per minute of load, per engine, at a fixed worker count — counting what retention removed as well as what remains.
- Claim contention: the share of claims that found another worker holding one, or lost theirs to a takeover.
- Claim and checkpoint latency, p50 and p95, measured around the repository calls themselves.
- Retention cost in milliseconds per thousand rows touched.
- The metrics snapshot's wall time against the table the run produced.

Numbers are written to the run's `report.json`, summarised in the workflow's job summary, and kept as a workflow artifact with every process's log. A baseline per engine lives in `workbench/soak/baseline.json`, accepted by a maintainer from a run they have read; the report compares each run to it and says in words when a number has moved by more than a quarter. **A move is a question to answer, not a red build** — shared CI runners vary by more than the regressions worth catching, and a flaky gate is a gate everyone learns to re-run until it is green ([decision 0013](decisions/0013-what-the-soak-proves.md)).

## What gates 1.0

Fourteen consecutive scheduled runs clean across all six engine cells, plus one multi-hour sustained run per engine. Not a calendar window — the reasoning is in [decision 0013](decisions/0013-what-the-soak-proves.md), and the roadmap's exit criterion is amended to match ([ROADMAP.md](../ROADMAP.md)).

The soak does not stop at 1.0. Rare defects are found by runs, and the runs keep coming afterwards.

## When it fails

A failed soak run is a defect report with an unusually good reproduction: the workload, the faults with the second each was injected, every process's log, and the report are all in the artifact. The procedure is the one every milestone has used — reproduce it in the fast suite as the regression test that would have caught it, fix it, and keep both.

A run that fails to *start* — an image that moved, a broker that never came up — is infrastructure, fixed in the workflow, and does not count toward the fourteen.
