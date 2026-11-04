# Decision records

Each file here records one decision that shaped Laraflow: what was decided, the forces behind it, what was rejected, and what it costs. The contract documents in `docs/` say *what* the package guarantees; these say *why*, so a later change can argue with the reasoning instead of rediscovering it.

Records are numbered in the order they were written, not the order the decisions were made: 0001–0006 were backfilled at Milestone 11 from the changelog and the contract documents, where the reasoning already lived in pieces.

| # | Decision | Status |
|---|---|---|
| [0001](0001-at-least-once-execution.md) | Execution is at-least-once; idempotency is a tool, not a promise | Accepted |
| [0002](0002-claims-are-compare-and-set.md) | The database claim is the only authority over who runs an execution | Accepted |
| [0003](0003-events-are-observational.md) | Lifecycle events are observational and fire after the write | Accepted |
| [0004](0004-timestamps-are-stored-in-utc.md) | Every stored timestamp is UTC | Accepted |
| [0005](0005-early-signals-are-buffered.md) | A signal that arrives early is kept, not refused | Accepted |
| [0006](0006-a-small-final-public-surface.md) | Public classes are final; the repository is a public reader and an experimental store | Accepted |
| [0007](0007-bridges-live-in-this-repository.md) | Metrics bridges live in this repository and are split out to publish | Accepted |
| [0008](0008-documentation-site-on-vitepress.md) | The documentation site is built with VitePress from `docs/` | Accepted |
| [0009](0009-one-release-per-milestone.md) | Milestone 11 is 0.7.0 and Milestone 12 is 1.0.0 | Accepted |

## Writing one

Copy the shape of an existing record: **Status**, **Context**, **Decision**, **Rejected**, **Consequences**. Keep it to what a reviewer needs in order to disagree well. A record is never edited to reverse it — a new record supersedes it, and the old one's status says by which.

A pull request that changes a guarantee one of these records rests on must add a superseding record; the maintainers ([CONTRIBUTING.md](../../CONTRIBUTING.md)) decide whether it is accepted.
