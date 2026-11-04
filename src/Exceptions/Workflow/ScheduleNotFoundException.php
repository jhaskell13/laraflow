<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A schedule operation named a workflow class no schedule row exists
 * for — never synchronised, or forgotten.
 */
final class ScheduleNotFoundException extends WorkflowException
{
    public static function forWorkflow(string $workflow): self
    {
        return new self(
            "No schedule exists for [{$workflow}]. A schedule row is created the first time "
            .'the tick run synchronises the declared class; check the class name and laraflow.schedules.workflows.'
        );
    }
}
