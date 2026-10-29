<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * Under FailurePolicy::Continue, a step that failed terminally in an
 * earlier claim of the same execution is not re-run when the execution
 * resumes from a retry or a wait — its budget was spent — but it still
 * decides the execution's outcome. This is the failure the execution
 * records when no newer one occurred.
 */
class StepPreviouslyFailedException extends WorkflowException
{
    public static function forStep(string $step, int $position, ?string $error): self
    {
        return new self(sprintf(
            'Step [%s] at position %d failed in an earlier run of this execution%s',
            $step,
            $position,
            $error === null || $error === '' ? '.' : ': '.$error,
        ));
    }
}
