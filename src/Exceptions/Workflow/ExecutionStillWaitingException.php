<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use DateTimeImmutable;

/**
 * A claim was refused because the execution is parked on a wait that is
 * not yet due — and, for a signal wait with no timeout, never will be
 * until the signal arrives.
 */
class ExecutionStillWaitingException extends WorkflowException
{
    public static function forId(int|string $id, ?DateTimeImmutable $nextEventAt): self
    {
        $until = $nextEventAt === null
            ? 'indefinitely, until its signal arrives'
            : 'until '.$nextEventAt->format(DATE_ATOM);

        return new self("Execution [{$id}] is waiting {$until}.");
    }
}
