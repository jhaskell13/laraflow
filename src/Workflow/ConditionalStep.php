<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * A step that may decide, from the context, that it has nothing to do.
 *
 * The condition is consulted at the step's turn — after the cancellation
 * check, before the step is marked running. A false answer durably marks
 * the step `skipped` and the runner moves on: no attempt is counted, no
 * output is recorded, the context is untouched, and recovery replays the
 * recorded decision rather than re-evaluating it.
 *
 * Conditions must be pure functions of the context: same context, same
 * answer. A worker that crashes after evaluating and before persisting
 * the skip will evaluate again on recovery against the identical
 * restored context; a condition reading the clock, a random source, or
 * external state breaks that window's determinism. The full contract is
 * docs/advanced-workflows.md.
 */
interface ConditionalStep
{
    public function shouldRun(WorkflowContext $context): bool;
}
