<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A signal was delivered to an execution that can never take it: one
 * that has finished (completed, cancelled, failed) or is compensating,
 * so no step will park on it again; one that does not exist; or a
 * reserved `laraflow.` name nothing is parked on. Refused loudly rather
 * than absorbed: a lost approval is a bug somewhere, and silence would
 * hide whose.
 *
 * Not a refusal since 0.6.0: a signal for an unfinished execution that
 * is not parked on the name *yet* — it is kept in the durable signal
 * inbox and answers the park that asks for it
 * (docs/advanced-workflows.md).
 */
final class SignalRefusedException extends WorkflowException
{
    public static function forSignal(int $executionId, string $signal): self
    {
        return new self(
            "Execution [{$executionId}] is not waiting on signal [{$signal}] and never will: "
            .'it has finished or is compensating, it does not exist, or the name is reserved.'
        );
    }
}
