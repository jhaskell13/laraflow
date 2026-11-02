<?php

declare(strict_types=1);

namespace Laraflow\Support;

use InvalidArgumentException;
use JsonException;

/**
 * @internal How JSON typed by a person becomes the array a workflow's
 * input or a signal's payload must be — the console's options and the
 * dashboard's forms both.
 */
final class JsonObject
{
    /**
     * @param  string  $field  How the caller refers to the field in the message.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the value is not a JSON object.
     */
    public static function parse(?string $raw, string $field): array
    {
        $raw = $raw === null ? '' : trim($raw);

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("The {$field} must be valid JSON: ".$exception->getMessage().'.');
        }

        // An object seeds by key, so only an object will do. `[]` and
        // `{}` both decode to the empty array and are accepted as
        // "nothing" — PHP cannot tell them apart, and neither carries
        // a key. A non-empty list has no keys to seed with.
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException(sprintf(
                'The %s must be a JSON object, e.g. {"order_id": 42}; got %s.',
                $field,
                is_array($decoded) ? 'a list' : get_debug_type($decoded),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
