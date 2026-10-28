<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Header maps merged the way HTTP reads them: by name, case-insensitively.
 *
 * Laraflow layers headers four times on the way to the wire — connector
 * class, connection, request, authenticator — and each layer is written
 * by someone else. A connection configuring `accept:` under a class that
 * declared `Accept:` means to override it; a case-sensitive array_merge
 * sends both. Every layer merges through here so the rule is one rule.
 */
final class Headers
{
    /**
     * $over wins by name regardless of case, and keeps its own spelling;
     * everything in $under that $over does not name survives unchanged.
     *
     * @param  array<string, string>  $under
     * @param  array<string, string>  $over
     * @return array<string, string>
     */
    public static function merge(array $under, array $over): array
    {
        foreach ($over as $name => $value) {
            $under = self::set($under, $name, $value);
        }

        return $under;
    }

    /**
     * One header set by name regardless of case, replacing any spelling
     * of it already present — in place, so a layer that overrides a
     * header does not also reorder the map the layer below built.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function set(array $headers, string $name, string $value): array
    {
        $result = [];
        $placed = false;

        foreach ($headers as $existing => $current) {
            if (strcasecmp((string) $existing, $name) !== 0) {
                $result[$existing] = $current;
            } elseif (! $placed) {
                $result[$name] = $value;
                $placed = true;
            }
        }

        if (! $placed) {
            $result[$name] = $value;
        }

        return $result;
    }
}
