<?php

namespace Laraflow\Support;

use Laraflow\Exceptions\Workflow\UnserializableValueException;

/**
 * Enforces the serialization contract for durable values.
 *
 * Workflow input, step output, and context data are persisted to JSON
 * columns and rebuilt in other processes. Only values that survive that
 * round trip unchanged are accepted: null, bool, int, finite float,
 * string, and arrays of those.
 */
class DurableValue
{
    /**
     * @throws UnserializableValueException if the value (or any nested
     *         element) cannot be persisted as JSON without loss.
     */
    public static function assert(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw UnserializableValueException::at($path, $value);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $element) {
                self::assert($element, $path . '.' . $key);
            }

            return;
        }

        throw UnserializableValueException::at($path, $value);
    }
}
