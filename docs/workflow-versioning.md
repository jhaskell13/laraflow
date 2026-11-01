# Workflow versioning

> This document was written as the design contract for Milestone 8's workflow versioning before any of it was built, and is now the shipped contract. The enforcing suite is `tests/Feature/Workflow/WorkflowVersioningTest.php`. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.

## The problem this solves

The full step plan is persisted at `start()`, and `execute()` refuses to run an execution whose persisted plan does not match the workflow's current `steps()` — same class, same steps, same order, or `WorkflowDefinitionMismatchException`. That refusal is load-bearing: durable state must never be silently reinterpreted by changed code ([execution-lifecycle.md](execution-lifecycle.md#the-plan-snapshot)).

Its cost is that **a deploy which changes a workflow strands every in-flight execution of it**. They cannot complete, cannot be recovered, and the only remedy today is to redeploy the old step list, drain, and deploy again. For a workflow with week-long approval waits, that is not a remedy.

Versioning keeps the refusal as the default and adds one way past it: an explicit, declared, tested migration from the plan that was persisted to the plan the code now describes.

## Shared principles

- **The refusal stays the default.** A workflow that changes `steps()` without declaring a version and a migration fails exactly as it does today. Versioning is opt-in per workflow, and silence means "refuse".
- **The record is immutable; only the undecided future is rewritten.** A migration may add, remove, and replace steps that have not run. It may never alter, reorder, or erase a step that did.
- **Migrations are declared, not inferred.** Laraflow never diffs two step lists and guesses what an author meant. The author says what changed.
- **No new recovery windows.** A migration runs under the existing claim, inside one transaction, before the first step write — so a faulting migration is bounded by the `fruitless_claims` counter that already bounds every fault in that position. No new scan, no new state, no new mechanism.
- **Schema changes are additive**, arrive as new dated migrations under the freeze, and ride the harness in `tests/Feature/Database/UpgradePathTest.php`.

## Declaring a version

```php
class FulfilOrder extends Workflow
{
    public function version(): int
    {
        return 2;
    }

    public function steps(): array
    {
        return [ReserveInventory::class, ChargePayment::class, RecordTaxLiability::class, SendReceipt::class];
    }

    public function planMigrations(): array
    {
        return [
            // From version 1 to 2: tax recording was added after payment.
            1 => fn (PlanDraft $plan) => $plan->insertAfter(ChargePayment::class, RecordTaxLiability::class),
        ];
    }
}
```

`version(): int` defaults to `1`, so every existing workflow is version 1 and nothing changes for an application that never overrides it. The value is recorded as `plan_version` on the executions row at `start()`, alongside the plan it describes.

`planMigrations(): array<int, callable(PlanDraft): mixed>` is **keyed by the version being migrated *from***, each entry moving the plan one version forward. Closures rather than classes: a migration is three lines that belong beside the step list they explain, and `PlanDraft` makes them unit-testable without a container, a database, or an execution.

## When a migration runs

On `execute()`, after the claim and the plan read, before the first step write:

| `plan_version` vs `version()` | Behaviour |
|---|---|
| Equal | Nothing happens. The existing plan check applies unchanged. |
| Persisted **lower** | The declared migrations are chained from the persisted version to the current one, then the plan check runs against the result. |
| Persisted **higher** | Nothing is migrated: the code is older than the record — a rollback — and migrating backwards would mean inventing a plan nobody declared. The ordinary plan check then refuses the execution with `WorkflowDefinitionMismatchException`, unless the older code's plan happens to be identical, in which case there is nothing to refuse and it runs with `plan_version` left where it was. |

**Chained, one hop at a time.** An execution at version 1 meeting code at version 4 runs the migrations keyed `1`, `2`, and `3`, in that order, each against the output of the last. A missing hop is refused by name (`there is no plan migration from version 2 to 3`) rather than skipped, because a skipped hop produces a plan no author ever described.

**Lazily, never eagerly.** There is no deploy hook, and rewriting every in-flight execution at boot would be an unbounded write at the worst possible moment. Each execution migrates itself the next time it is claimed, and pays only for itself.

**Under the claim, in one transaction.** The rewrite of the step rows and the bump of `plan_version` commit together, guarded by the claim token like every other durable write. A worker that loses the claim mid-migration writes nothing. A migration closure that throws leaves the execution claimed with no step write, which is precisely the fault the `fruitless_claims` counter already bounds: after `recovery.abandon_after_attempts` such claims the execution is reported as abandoned rather than re-dispatched forever ([execution-lifecycle.md](execution-lifecycle.md#claiming-and-concurrency)).

**The definition judges before anything is written.** A migration is not trusted to be correct. Its output goes through the same validation a plan gets at `start()` — non-empty, every entry an existing class implementing `Step` — and is then compared to `steps()` itself. A migration that produces something other than the current definition is refused with `PlanMigrationRefusedException`, naming both lists, and **nothing is committed**: the execution stays on its old plan and version, so the author fixes the closure and the next claim tries again. (The alternative — commit, announce, then refuse — would leave a plan no code describes, and `plan_version` saying the migration had happened.) The migration is a proposal; the definition is the judge. The ordinary plan check still runs afterwards, now as a formality.

## What a migration may change

**Only the undecided tail.** Let the *frozen prefix* be every step up to and including the highest-positioned row that is not `pending`. Everything after it is the undecided tail, and every row in the tail is `pending` by construction. A migration may rewrite the tail freely and may not touch the frozen prefix at all.

This single rule is what makes the whole mechanism safe, and it rests on an invariant the schema already guarantees: **a `pending` row has no output, no attempt history, and no context contribution.** Nothing refers to it and nothing was learned from it, so it can be renumbered, removed, or replaced without rewriting history. Every other row is the record of something that happened.

| Operation | Allowed when |
|---|---|
| `append(Step::class)` | Always — the end of the plan is always in the tail. |
| `insertAfter(Existing::class, New::class)` | The insertion point is at or after the end of the frozen prefix. |
| `insertBefore(Existing::class, New::class)` | `Existing` is in the tail. |
| `remove(Step::class)` | The step is in the tail. |
| `replace(Old::class, New::class)` | `Old` is in the tail. |

Anything else is refused with `PlanMigrationRefusedException`, naming the step and the status that froze it — or, for a `pending` row that sits before a decided one (which `FailurePolicy::Continue` can leave behind), the decided row that froze it. Refusals are deliberate dead ends, not errors to work around: an execution that already charged a card cannot be told it never planned to. One consequence worth saying plainly: the step most likely to need a hot fix in flight — the one currently `retrying` or `failed` — is decided, and cannot be replaced by a migration. The remedy there is to fix the class in place; the record keeps its name.

The consequence worth stating plainly: **an execution far enough along cannot receive a change aimed at steps it has passed.** It finishes under the plan it has. That is the correct outcome, and it is why the migration path is for *in-flight* work rather than retroactive correction.

`PlanDraft` operates on step classes rather than positions, because positions shift as the draft changes and a migration written against them would be read differently depending on what ran. Addressing by class is what makes a repeat ambiguous, so a plan that repeats a step class cannot be migrated at all, and no operation may introduce a repeat — both are refused with `PlanMigrationRefusedException`. A workflow whose `steps()` legitimately repeats a class (the runtime allows it) therefore cannot use plan migrations. For a migration's own tests, `PlanDraft::of($workflow, $steps, $frozenBefore)` builds a draft from a plain list, with no container, database, or execution.

## Persistence

One additive migration adds `plan_version` to `laraflow_workflow_executions`: an unsigned integer, default `1`, not null, unindexed (it is read per claimed execution, never scanned). A row that predates the column reads as version 1, which is exactly what it is.

The rewrite deletes the tail's step rows and reinserts them at their new positions rather than updating positions in place, because `(workflow_execution_id, position)` is unique and an in-place renumber collides with itself halfway through on every engine. Deleting them is safe for the reason above: a `pending` row has no attempts to orphan and no output to lose.

**There is no plan-migration history table.** `plan_version` says which plan an execution is on; the `WorkflowPlanMigrated` event says when it moved, and the log record says what every operation did; the authoritative account of what each hop means is the `planMigrations()` array in the code, which is version-controlled and reviewable. Another table recording "step X was inserted at 14:02" would duplicate that and grow without bound. The trade-off is that an operator reconstructing a migration's effect reads the code at that version rather than the database — which is the same place they would read to understand the plan itself.

## Observability

- **`WorkflowPlanMigrated`** (public, `readonly`), fired after the commit: identifiers, workflow class, `fromVersion`, `toVersion`, and the resulting step count. No payload, input, or context ever appears.
- **`laraflow:show`** prints the execution's plan version once it is above 1 — every execution of an unversioned workflow is version 1, and that line would be noise.
- A migration writes one log record at info level naming the execution, both versions, every operation applied (`insert RecordTaxLiability after ChargePayment`), and the resulting step count.

## Interactions

- **Sub-workflows.** A child's plan versions independently of its parent's; they are separate executions with separate plans. A parent migrating does not touch its children.
- **Retention.** `plan_version` is a small integer on a row retention already handles. Scrubbing does not clear it — it is structural, not payload.
- **Recovery.** An execution stranded by a definition change today is reported as abandoned after the fruitless-claim bound. With a declared migration it migrates on the next claim and proceeds; with none, it is abandoned exactly as before.
- **Conditional steps.** A step inserted into the tail is `pending`, so a condition on it votes normally the first time it is reached. A skipped step is not `pending` and is therefore frozen, like any other decided row.

## Compatibility notes

- `Workflow::version()` and `Workflow::planMigrations()` are new optional methods on the Public base class with defaults, so no existing workflow changes.
- `plan_version` is additive with a default, so no existing row changes.
- The failure mode for an undeclared change is unchanged: `WorkflowDefinitionMismatchException`, same message.
- `PlanDraft`, `PlanMigrationRefusedException`, and `WorkflowPlanMigrated` are new Public names.

## Decided in step 8, implemented in step 9

Everything above is built and enforced by `WorkflowVersioningTest`, which carries the milestone's exit criterion: an in-flight execution survives a workflow definition change through the documented migration path, on every supported engine.
