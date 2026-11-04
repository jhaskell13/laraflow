<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Another scheduler inserted this tick's row first. Not a fault: with
 * several schedulers running, losing the insert race is the designed
 * outcome for all but one of them, and the loser's transaction —
 * execution included — rolls back whole (docs/schedules.md, "The tick").
 */
class TickAlreadyClaimedException extends WorkflowException
{
    public static function for(string $workflow, DateTimeImmutable $tickAt): self
    {
        return new self(
            "The [{$workflow}] tick at {$tickAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i')} UTC is already claimed; "
            .'another scheduler started its execution.'
        );
    }
}
