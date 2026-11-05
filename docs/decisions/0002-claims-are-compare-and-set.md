# 0002 — The database claim is the only authority over who runs an execution

**Status:** Accepted (Milestone 1; extended at Milestones 2, 10, and 0.6.0).

## Context

Queues deliver at least once, workers die mid-step, and recovery scans re-queue work. Something must decide, under all of that, which process may run an execution.

## Decision

A single atomic compare-and-set on the execution row: a claim succeeds only if the row is in a claimable state, and every later write is guarded by the claim token it won. Duplicate deliveries are refused as no-ops; a reclaimed worker's writes are rejected with `ClaimLostException`. Tenant ceilings are consulted inside the claim (Milestone 10), and re-running a terminal failure is an explicit operator opt-in rather than something any delivery can do (0.6.0). See [queue-and-recovery.md](../queue-and-recovery.md).

## Rejected

- **Cache locks or `ShouldBeUnique`.** A second source of truth that can disagree with the database, and that vanishes when the cache is flushed.
- **Advisory locks.** Not portable across the five supported engines.

## Consequences

Correctness rests on the database alone, which is why every engine runs the full suite in CI. A custom `ExecutionStore` must implement the same compare-and-set semantics, which is why the store half of the repository stays experimental until a second driver proves them ([0006](0006-a-small-final-public-surface.md)).
