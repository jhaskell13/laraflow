<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A remember() key that cannot be stored or cannot be told apart from
 * one already stored. Never retried; the same key is invalid every
 * time.
 */
final class InvalidIdempotencyKeyException extends WorkflowException
{
    public static function empty(): self
    {
        return new self('An idempotency key must not be empty.');
    }

    public static function tooLong(string $key, int $length, int $max): self
    {
        return new self(sprintf(
            'The idempotency key [%s] is %d characters; the limit is %d.',
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

    public static function surroundingWhitespace(string $key): self
    {
        return new self(sprintf(
            'The idempotency key [%s] begins or ends with whitespace, which some databases ignore when comparing keys.',
            $key,
        ));
    }

    public static function notUtf8(): self
    {
        return new self('An idempotency key must be valid UTF-8.');
    }

    /**
     * The database considers two different byte strings the same key —
     * MySQL, MariaDB, and SQL Server compare case-insensitively by
     * default — so the second can neither be stored nor told apart from
     * the first. Refused by name rather than treated as a replay of
     * work it did not ask for.
     */
    public static function collides(string $key, string $existing): self
    {
        return new self(sprintf(
            'The idempotency key [%s] collides with the already-recorded key [%s]: this database compares them as equal. '
            .'Choose keys that differ in more than case or whitespace.',
            $key,
            $existing,
        ));
    }
}
