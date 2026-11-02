<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use DateTimeImmutable;

/**
 * A previous attempt began idempotent work and never recorded an
 * outcome. The side effect may or may not have taken place, and
 * Laraflow refuses to decide: silently re-running is how a customer is
 * charged twice, silently skipping is how they are never charged.
 *
 * Never retried: a retry finds the same pending record and raises
 * again. Supply a reconcile callback to remember(), or resolve the
 * outcome by hand. See docs/idempotency.md.
 */
class IdempotentOutcomeUncertainException extends WorkflowException
{
    public static function forKey(string $step, int $position, string $key, DateTimeImmutable $startedAt): self
    {
        return new self(sprintf(
            'Step [%s] at position %d began idempotent work [%s] at %s and no outcome was recorded. '
            .'The side effect may or may not have taken place. Supply a reconcile callback, or resolve it manually.',
            $step,
            $position,
            $key,
            $startedAt->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * Concurrent attempts kept moving the record between this attempt's
     * reads, so its state could not be established. Only live
     * contention on one key reaches this, and it is reported as what it
     * is rather than as a replay or a fresh start.
     */
    public static function contended(string $step, int $position, string $key): self
    {
        return new self(sprintf(
            'Step [%s] at position %d could not establish the state of idempotent work [%s]: concurrent attempts kept '
            .'changing it. The side effect may or may not have taken place. Resolve it manually.',
            $step,
            $position,
            $key,
        ));
    }
}
