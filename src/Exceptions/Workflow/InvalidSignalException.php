<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use Laraflow\Workflow\Wait;

/**
 * A signal name an application supplied cannot be used: empty, longer
 * than the column that stores it, or inside Laraflow's reserved prefix.
 * The prefix check is case-insensitive on purpose — the database match
 * that selects a waiting step is case-insensitive on MySQL, MariaDB,
 * and SQL Server, so a differently-cased spelling must be refused too.
 */
class InvalidSignalException extends WorkflowException
{
    public static function empty(): self
    {
        return new self('A signal name must not be empty.');
    }

    public static function tooLong(int $length): self
    {
        return new self('A signal name must be at most '.Wait::MAX_SIGNAL_LENGTH." characters; {$length} given.");
    }

    public static function reserved(string $name): self
    {
        return new self(
            "Signal name [{$name}] is inside the reserved [".Wait::RESERVED_PREFIX.'] prefix, '
            .'which belongs to Laraflow itself, in any casing.'
        );
    }
}
