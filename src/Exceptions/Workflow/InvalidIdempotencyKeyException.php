<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A remember() key that cannot be stored: empty, too long once scoped
 * to its step, or carrying control characters. Never retried; the
 * same key is invalid every time.
 */
class InvalidIdempotencyKeyException extends WorkflowException
{
    public static function empty(): self
    {
        return new self('An idempotency key must not be empty.');
    }

    public static function tooLong(string $key, int $length, int $max): self
    {
        return new self(sprintf(
            'The idempotency key [%s] is %d characters once scoped to its step; the limit is %d.',
            $key,
            $length,
            $max,
        ));
    }

    public static function controlCharacters(string $key): self
    {
        return new self(sprintf(
            'The idempotency key [%s] contains control characters.',
            addcslashes($key, "\0..\37\177"),
        ));
    }
}
