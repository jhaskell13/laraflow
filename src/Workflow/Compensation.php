<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * What a step's compensate() is given: what the step produced, the
 * context as the execution now stands, and why the execution is being
 * undone.
 *
 * The output is the point. A step that charged a card recorded the
 * charge id; undoing it means refunding that id, not guessing. A step
 * that completed with no output has nothing to undo and is still
 * offered the context.
 */
final readonly class Compensation
{
    public function __construct(
        private WorkflowContext $context,
        public int $executionId,
        public string $step,
        public int $position,
        public mixed $output,
        public string $failure,
        public ?string $failureClass,
        public int $attempt,
    ) {}

    /**
     * What the compensated step returned from handle(), as it was
     * persisted. Null when it produced nothing.
     */
    public function output(): mixed
    {
        return $this->output;
    }

    /**
     * The execution's context. Reads what every step contributed, and
     * `remember()` is available here for the same reason it is in a
     * step: a worker can die between performing the undo and recording
     * it, and a compensation that repeats is a refund that repeats.
     */
    public function context(): WorkflowContext
    {
        return $this->context;
    }

    /**
     * The message of the failure that is being compensated — the one
     * that ended the execution, not this step's.
     */
    public function failure(): string
    {
        return $this->failure;
    }
}
