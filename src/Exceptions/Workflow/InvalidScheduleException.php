<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use Laraflow\Workflow\Workflow;
use Throwable;

/**
 * A class listed for scheduling cannot be scheduled — it does not
 * exist, is not a workflow, cannot be built, declares no schedule, or
 * declares one that is invalid. Refused by name when the registry is
 * read, because a schedule that silently never fires is the failure
 * scheduling exists to remove (docs/schedules.md, "Registration").
 */
class InvalidScheduleException extends WorkflowException
{
    public static function missing(string $workflow): self
    {
        return new self(
            "[{$workflow}] is listed in laraflow.schedules.workflows but no such class exists. "
            .'Remove the entry, or fix the class name.'
        );
    }

    public static function notAWorkflow(string $workflow): self
    {
        return new self(
            "[{$workflow}] is listed in laraflow.schedules.workflows but is not a ".Workflow::class.'. '
            .'Only workflow classes can be scheduled.'
        );
    }

    public static function unresolvable(string $workflow, Throwable $previous): self
    {
        return new self(
            "[{$workflow}] is scheduled but the container could not build it: {$previous->getMessage()}",
            previous: $previous,
        );
    }

    public static function notScheduled(string $workflow): self
    {
        return new self(
            "[{$workflow}] is listed in laraflow.schedules.workflows but its schedule() returns null. "
            .'Declare one, or remove the entry — a listing with no declaration is a schedule that never fires.'
        );
    }

    public static function invalid(string $workflow, Throwable $previous): self
    {
        return new self(
            "[{$workflow}] declares a schedule that is not usable: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
