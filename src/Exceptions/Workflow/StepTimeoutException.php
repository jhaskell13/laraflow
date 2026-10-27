<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use DateTimeImmutable;

/**
 * Recorded as the failure of a step attempt whose deadline (timeout_at)
 * passed and whose worker's claim then went stale without a checkpoint.
 *
 * Laraflow does not preempt running PHP — a step is expected to bound
 * its own work using the deadline the context exposes. This exception
 * is the *classification* a recovering worker assigns to an overrun
 * attempt, distinguishing a timeout (charged to the retry budget) from
 * plain worker loss before the deadline (a free re-run) and from an
 * ordinary thrown failure.
 */
class StepTimeoutException extends WorkflowException
{
    public static function forStep(
        string $step,
        int $position,
        DateTimeImmutable $timeoutAt,
        int $attempt
    ): self {
        return new self(sprintf(
            'Step [%s] at position [%d] exceeded its deadline [%s] on attempt %d and its worker never checkpointed it.',
            $step,
            $position,
            $timeoutAt->format('Y-m-d H:i:s'),
            $attempt,
        ));
    }
}
