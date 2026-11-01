<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * remember() was called on a context the runner did not build — one
 * constructed by hand, with no execution behind it and no store to
 * record into. Raised rather than silently running the work, because
 * silently running it is the behaviour the store exists to end.
 */
class IdempotencyStoreUnavailableException extends WorkflowException
{
    public static function forContext(): self
    {
        return new self(
            'remember() needs a context the runner built: this one has no execution, step, or idempotency store behind it. '
            .'Run the step through WorkflowRunner, or test the work closure directly.'
        );
    }
}
