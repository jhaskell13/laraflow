<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * An ExecutionOptions value was rejected at construction, before
 * anything was persisted.
 *
 * Correlation identifiers are only useful if they survive storage
 * intact and are safe to put in a log line, so both conditions are
 * enforced where the value enters the package rather than at each
 * place it is later written.
 */
class InvalidExecutionOptionsException extends WorkflowException
{
    public static function tooLong(string $name, int $length, int $max): self
    {
        return new self(
            "Execution option [{$name}] is {$length} characters, which exceeds the maximum of {$max}. "
            .'It is persisted to an indexed string column; a truncated identifier correlates with nothing.'
        );
    }

    public static function controlCharacters(string $name): self
    {
        return new self(
            "Execution option [{$name}] contains control characters. "
            .'These values appear in log records and logging context, where a newline would let the '
            .'identifier forge a second log line.'
        );
    }
}
