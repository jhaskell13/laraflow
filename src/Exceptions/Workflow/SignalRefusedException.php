<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A signal was delivered to an execution that is not waiting on it —
 * wrong name, already resumed, timed out meanwhile, or not waiting at
 * all. Refused loudly rather than absorbed: a lost approval is a bug
 * somewhere, and silence would hide whose.
 */
class SignalRefusedException extends WorkflowException
{
    public static function forSignal(int|string $executionId, string $signal): self
    {
        return new self(
            "Execution [{$executionId}] is not waiting on signal [{$signal}]: "
            .'it may have resumed, timed out, finished, or never waited on that name.'
        );
    }
}
