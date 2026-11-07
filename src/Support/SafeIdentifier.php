<?php

declare(strict_types=1);

namespace Laraflow\Support;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * What makes a caller-supplied identifier safe to carry.
 *
 * Correlation ids, tenant ids, and signal names all end up in the same
 * places: a `string` column, Laravel's log context, a lifecycle event,
 * a `--json` document, and a dashboard page. A newline in one lets a
 * crafted identifier forge a second log line, and a byte sequence that
 * is not UTF-8 makes `json_encode()` fail — so an operator command or
 * an HTTP response built from the record throws where it should have
 * answered. Rejecting at the boundary keeps every downstream sink
 * honest without each of them re-sanitising.
 *
 * The rule lives here, once, because it was in `ExecutionOptions` and
 * not in `Wait`, so signal names — which the trigger endpoint takes
 * from a URL — were held to a weaker standard than the identifiers
 * beside them (Milestone 12 security review).
 */
final class SafeIdentifier
{
    public const TOO_LONG = 'too_long';

    public const INVALID_ENCODING = 'invalid_encoding';

    public const CONTROL_CHARACTERS = 'control_characters';

    /**
     * Why $value may not be carried, or null when it may.
     *
     * Encoding is asked before length: `mb_strlen()` of a malformed
     * string is a guess, so reporting it as too long would name the
     * wrong fault.
     */
    public static function reasonFor(string $value, int $maxLength): ?string
    {
        // On malformed UTF-8 `preg_match` returns false rather than 0 —
        // which must read as a refusal, not a pass: a byte sequence the
        // guard cannot inspect is one the sinks cannot be trusted with.
        if (! mb_check_encoding($value, 'UTF-8')) {
            return self::INVALID_ENCODING;
        }

        if (mb_strlen($value) > $maxLength) {
            return self::TOO_LONG;
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return self::CONTROL_CHARACTERS;
        }

        return null;
    }

    /**
     * An untrusted string made safe to put in a log line or a response:
     * bounded, with control bytes dropped. Byte-level on purpose —
     * invalid UTF-8 must not survive into `json_encode()`, which fails
     * on it.
     */
    public static function presentable(string $value, int $maxBytes = 255): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', substr($value, 0, $maxBytes));

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
