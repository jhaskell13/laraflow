<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A sub-workflow reached a resting outcome that is not completion, and
 * the parent step fails with it — subject to the parent step's retry
 * policy, where a retry re-dispatches the same child so it resumes from
 * its own checkpoints. A cancelled or missing child cannot be resumed;
 * retrying the parent step reports the same outcome until an operator
 * intervenes.
 */
class ChildWorkflowFailedException extends WorkflowException
{
    public static function forOutcome(string $step, int $position, int|string $childId, string $status): self
    {
        return new self(sprintf(
            'Step [%s] at position %d waited on child execution [%s], which ended [%s] rather than completed.',
            $step,
            $position,
            $childId,
            $status,
        ));
    }

    public static function missing(string $step, int $position, int|string $childId): self
    {
        return new self(sprintf(
            'Step [%s] at position %d waited on child execution [%s], which no longer exists — pruned by retention, or deleted.',
            $step,
            $position,
            $childId,
        ));
    }
}
