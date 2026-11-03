<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use Cron\CronExpression;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * @internal The tick arithmetic, in one place: a cron expression, a
 * timezone, and the clock in; UTC instants out. Every scheduler that
 * reads the same minute computes the same instants here, which is what
 * makes the unique tick index a guarantee rather than a hope.
 */
final class Ticks
{
    /**
     * The most instants between() will walk. A gap wider than this —
     * a decade-long outage on a per-minute schedule — records the bound
     * rather than costing a decade of cron arithmetic; every scan is
     * bounded, this one included (docs/schedules.md, "Missed ticks").
     */
    public const MAX_COUNTED = 10000;

    /**
     * The expression's most recent instant at or before $now, read in
     * $timezone, returned in UTC. A pure function of its arguments: two
     * schedulers that read the same minute name the same instant.
     */
    public static function mostRecent(string $expression, string $timezone, DateTimeImmutable $now): DateTimeImmutable
    {
        $local = DateTime::createFromImmutable($now->setTimezone(new DateTimeZone($timezone)));

        $previous = (new CronExpression($expression))->getPreviousRunDate($local, 0, true, $timezone);

        return DateTimeImmutable::createFromMutable($previous)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The expression's first instant strictly after $after, or null when
     * the library's search horizon finds none — an expression whose next
     * match is years away.
     */
    public static function nextAfter(string $expression, string $timezone, DateTimeImmutable $after): ?DateTimeImmutable
    {
        $local = DateTime::createFromImmutable($after->setTimezone(new DateTimeZone($timezone)));

        try {
            $next = (new CronExpression($expression))->getNextRunDate($local, 0, false, $timezone);
        } catch (RuntimeException) {
            return null;
        }

        return DateTimeImmutable::createFromMutable($next)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The expression's instants strictly after $after and at or before
     * $until, oldest first, at most $cap of them — the gap a scheduler
     * came back to.
     *
     * @return list<DateTimeImmutable> UTC
     */
    public static function between(
        string $expression,
        string $timezone,
        DateTimeImmutable $after,
        DateTimeImmutable $until,
        int $cap = self::MAX_COUNTED,
    ): array {
        $cron = new CronExpression($expression);
        $zone = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');

        $instants = [];
        $cursor = DateTime::createFromImmutable($after->setTimezone($zone));

        while (count($instants) < $cap) {
            try {
                $next = $cron->getNextRunDate($cursor, 0, false, $timezone);
            } catch (RuntimeException) {
                // The library's search horizon — an expression whose next
                // match is years away. Nothing more to walk.
                break;
            }

            $instant = DateTimeImmutable::createFromMutable($next)->setTimezone($utc);

            if ($instant > $until) {
                break;
            }

            $instants[] = $instant;
            $cursor = $next;
        }

        return $instants;
    }
}
