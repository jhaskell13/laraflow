<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

class UnserializableValueException extends WorkflowException
{
    public static function at(string $path, mixed $value): self
    {
        $type = get_debug_type($value);

        return new self(
            "Value at [{$path}] of type [{$type}] cannot be persisted. "
            .'Durable values must be JSON-serializable: null, bool, int, finite float, '
            .'string, or arrays of those. Store an identifier and reload the object instead.'
        );
    }
}
