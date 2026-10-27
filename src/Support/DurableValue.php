<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Laraflow\Exceptions\Workflow\PayloadTooLargeException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;

/**
 * @internal Implementation detail; the contract it enforces is public
 * (docs/execution-lifecycle.md), this enforcement of it is not.
 *
 * Enforces the serialization contract for durable values.
 *
 * Workflow input, step output, and context data are persisted to JSON
 * columns and rebuilt in other processes. Only values that survive that
 * round trip unchanged are accepted: null, bool, int, finite float,
 * string, and arrays of those — and, when a limit is configured, only
 * payloads small enough to store.
 */
class DurableValue
{
    /**
     * Enforce both halves of the contract: the value must be
     * representable, and small enough to persist.
     *
     * @param  int|null  $maxBytes  Null disables the size limit.
     *
     * @throws UnserializableValueException
     * @throws PayloadTooLargeException
     */
    public static function check(mixed $value, string $path, ?int $maxBytes = null): void
    {
        self::assert($value, $path);
        self::assertSize($value, $path, $maxBytes);
    }

    /**
     * @throws UnserializableValueException if the value (or any nested
     *                                      element) cannot be persisted as JSON without loss.
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
                self::assert($element, $path.'.'.$key);
            }

            return;
        }

        throw UnserializableValueException::at($path, $value);
    }

    /**
     * Reject a payload larger than $maxBytes.
     *
     * Measured as the encoded JSON, because that is what reaches the
     * column — not the in-memory footprint of the PHP value, which
     * bears no fixed relation to it.
     *
     * @throws PayloadTooLargeException
     */
    public static function assertSize(mixed $value, string $path, ?int $maxBytes): void
    {
        if ($maxBytes === null) {
            return;
        }

        // Substituting invalid UTF-8 rather than failing: this method
        // measures, it does not validate. A string the JSON encoder
        // cannot represent is a serialization problem, and letting the
        // measurement return false here would silently skip the limit.
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            return;
        }

        $bytes = strlen($encoded);

        if ($bytes > $maxBytes) {
            throw PayloadTooLargeException::at($path, $bytes, $maxBytes);
        }
    }
}
