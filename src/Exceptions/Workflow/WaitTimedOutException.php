<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use DateTimeImmutable;

/**
 * A signal wait passed its deadline without the signal arriving.
 *
 * An ordinary step failure: charged to the step's retry policy, so a
 * retried approval step re-posts its request. A workflow that wants one
 * park and one deadline disables retries on that step.
 */
class WaitTimedOutException extends WorkflowException
{
    public static function forSignal(string $step, int $position, string $signal, DateTimeImmutable $deadline, int $attempt): self
    {
        return new self(sprintf(
            'Step [%s] at position %d waited for signal [%s] until %s (attempt %d) and it never arrived.',
            $step,
            $position,
            $signal,
            $deadline->format(DATE_ATOM),
            $attempt,
        ));
    }
}
