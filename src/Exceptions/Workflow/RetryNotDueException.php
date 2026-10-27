<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use DateTimeImmutable;

/**
 * A `retrying` execution was claimed before its `next_retry_at`.
 *
 * The schedule is part of the retry contract — backoff exists to protect
 * the failing dependency — so an early claim is refused rather than
 * silently honoured. Running a retry ahead of schedule is a deliberate
 * operator act: laraflow:execute --now, or execute(..., ignoreRetrySchedule: true).
 */
class RetryNotDueException extends WorkflowException
{
    public static function forId(int|string $id, ?DateTimeImmutable $nextRetryAt): self
    {
        $due = $nextRetryAt?->format('Y-m-d H:i:s') ?? 'an unknown time';

        return new self(
            "Workflow execution [{$id}] is retrying and not due until [{$due}]. "
            .'Wait for the schedule, or run it deliberately ahead of time with '
            .'laraflow:execute --now.'
        );
    }
}
