# 0013 — What the soak proves, and what it cannot

**Status:** Accepted (Milestone 12).

## Context

Milestone 12's exit criterion read: *the soak suite has run clean for a full support window with no recovery defect found*. The support window is 90 days ([releases.md](../releases.md)), so read literally the criterion dates 1.0 to February 2027 and makes the release wait on a calendar rather than on evidence.

That confuses duration with proof. Ninety days of a soak that exercises the wrong thing proves nothing; a fortnight of one that checks the right invariants on every engine, under injected faults, proves a great deal. Duration matters only because some defects are rare — and the way to buy rarity is runs across six engines, not months on one.

A second honesty problem sat beside it. "Database failover" is easy to write and hard to mean. Real failover is a replica set with a promoted primary; what a GitHub Actions service container can do is stop and start. Those are different tests, and only one of them is available.

## Decision

**The invariants are the deliverable, not the hours.** Each soak round ends by asserting what must be true of the records whatever happened during it — no execution resting in a non-terminal state past its bounds, no two attempt rows for one logical attempt, no fan-out that both completed and failed, exactly one tick per `(schedule, instant)`, no remembered key settled twice, no orphaned rows, every finished execution's plan matching its definition. A violation is a release blocker. These are the statements the durability contract actually makes; the load and the faults exist to try to break them.

**The budget that gates 1.0 is stated and falsifiable:** fourteen consecutive scheduled runs clean across all six engine cells, plus one multi-hour sustained run per engine. Not ninety days.

**The soak does not stop at 1.0.** It keeps running on its schedule afterwards, which is where the long tail of rare defects is actually found — after the release, by a machine, rather than before it, by waiting.

**The database fault is connection loss and recovery, and is described as such.** The soak stops and restarts the engine's container under load. That exercises what Laraflow does when its database goes away and comes back: claims that cannot be written, checkpoints that fail mid-transaction, and recovery afterwards. It does **not** exercise a promoted replica, a split brain, or replication lag, and [soak.md](../soak.md) says so in those words.

**Benchmark numbers are published with an advisory regression guard, not a hard gate.** Shared CI runners vary by more than the regressions worth catching. A hard gate on a noisy signal trains everyone to re-run the job until it is green, which is worse than no gate. The numbers are committed, charted over time, and a significant move is a question to answer — not a red build.

## Rejected

- **The literal ninety days.** It gates a release on a calendar, and nothing about the ninetieth day is more informative than the fourteenth.
- **A hard performance gate.** See above: a flaky gate is a gate everyone learns to ignore.
- **Standing up real failover topology** — a replica set per engine, in CI, for one assertion. The cost is enormous and the result would still not resemble any particular production deployment. Better to state plainly what is not covered.
- **Soaking only SQLite because it is fastest.** The engines are where this project has found its defects every time, in every milestone since 6.

## Consequences

1.0 ships on evidence that can be pointed at and argued with, and the roadmap's exit criterion is amended to match ([ROADMAP.md](../../ROADMAP.md)). What the soak does not cover — real failover, and production mileage of any kind — stays written down in [limitations.md](../limitations.md), which keeps saying there is none.
