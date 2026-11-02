<?php

declare(strict_types=1);

namespace Laraflow\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * @internal How Laraflow renders a date, a duration, a wait, and a
 * value wherever it shows one to a person — the console and the
 * dashboard both. One definition, so the two cannot disagree about what
 * "1.3 s" or "—" means, which is the same reason the console's commands
 * share their rendering through DisplaysExecutions.
 */
final class Formats
{
    /** What an absent value renders as, everywhere. */
    public const NONE = '—';

    public static function date(?DateTimeInterface $date): string
    {
        return $date?->format('Y-m-d H:i:s') ?? self::NONE;
    }

    /**
     * A duration in milliseconds: sub-second in ms, otherwise to a
     * tenth of a second.
     */
    public static function duration(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return self::NONE;
        }

        return $milliseconds < 1000 ? "{$milliseconds} ms" : sprintf('%.1f s', $milliseconds / 1000);
    }

    /**
     * Whole seconds, in the unit a person would pick: seconds, minutes
     * and seconds, or hours and minutes.
     */
    public static function seconds(?int $seconds): string
    {
        if ($seconds === null) {
            return self::NONE;
        }

        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => sprintf('%d min %d s', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%d h %d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        };
    }

    /**
     * How long ago, from $now — "3 s", "4 min 12 s", "2 h 5 min", or
     * days once an hour count stops meaning anything.
     */
    public static function age(?DateTimeInterface $since, DateTimeImmutable $now): string
    {
        if ($since === null) {
            return self::NONE;
        }

        $seconds = max(0, $now->getTimestamp() - $since->getTimestamp());

        if ($seconds >= 86400) {
            return sprintf('%d d %d h', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
        }

        return self::seconds($seconds);
    }

    /**
     * A persisted value as one line of JSON, cut to $limit characters
     * with an ellipsis. Never raw: a value that is not JSON-renderable
     * says so rather than leaking its PHP shape.
     */
    public static function value(mixed $value, int $limit): string
    {
        if ($value === null) {
            return self::NONE;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return '(unrenderable)';
        }

        return mb_strlen($json) > $limit ? mb_substr($json, 0, $limit - 1).'…' : $json;
    }
}
