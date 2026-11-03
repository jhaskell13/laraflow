<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * A step that knows how to undo itself.
 *
 * Compensation is opt-in by existing: a step that does not implement
 * this has nothing to undo, and a workflow whose steps none of them do
 * behaves exactly as it does today. There is no policy to set — the
 * declaration is the opt-in, as it is for ConditionalStep.
 *
 * When an execution fails terminally, Laraflow runs the declarations in
 * reverse over the steps that *completed*, skipping the step that
 * failed: a step that threw is in a state only the application knows,
 * and a step with no output has nothing to undo. Each compensation is
 * an ordinary step row of its own, so it has its own attempts, its own
 * retries under this step's policy, and its own place in
 * `laraflow:show`. The full contract is docs/advanced-workflows.md.
 *
 * Compensations must be idempotent for the same reason steps must: a
 * worker can die between performing the undo and recording it.
 * `$compensation->context()->remember()` is available and is the answer.
 */
interface CompensatableStep
{
    /**
     * Undo what handle() did. Throwing fails this compensation, which
     * is retried under the step's own policy; when its retries are
     * exhausted the pass stops rather than reaching past it to undo
     * earlier work the failed one may have depended on.
     */
    public function compensate(Compensation $compensation): void;
}
