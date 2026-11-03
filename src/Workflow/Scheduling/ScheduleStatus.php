<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use DateTimeImmutable;

/**
 * @internal What a schedule row's health is, answered one way for the
 * listing, the summary, and the dashboard — the AttentionScan rule: one
 * computation, however many renderers.
 */
final class ScheduleStatus
{
    /**
     * Unclaimed due instants at or past which a live schedule counts as
     * stalled. One is the ordinary gap between a tick falling due and
     * the next scheduler run claiming it; three means the scheduler has
     * sat out at least two full cycles — "more than two intervals
     * behind" (docs/schedules.md, "Observability").
     */
    private const STALLED_AT = 3;

    /**
     * A live, unpaused schedule whose due ticks are piling up unclaimed
     * — the signature of a scheduler that stopped running. An orphan
     * stalls too, eventually, which is correct: its ticks have stopped
     * for the same operational purposes.
     */
    public static function stalled(ScheduledWorkflow $row, DateTimeImmutable $now): bool
    {
        if ($row->isPaused() || $row->lastTickAt === null) {
            return false;
        }

        $due = Ticks::mostRecent($row->cron, $row->timezone, $now);

        if ($due->getTimestamp() <= $row->lastTickAt->getTimestamp()) {
            return false;
        }

        return count(Ticks::between($row->cron, $row->timezone, $row->lastTickAt, $due, cap: self::STALLED_AT)) >= self::STALLED_AT;
    }

    /**
     * When this schedule next fires — or should already have: the
     * earliest unclaimed due instant when one is standing, otherwise
     * the first instant after now. Null for a paused schedule, which
     * fires never until resumed.
     */
    public static function nextDue(ScheduledWorkflow $row, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($row->isPaused()) {
            return null;
        }

        if ($row->lastTickAt !== null) {
            $standing = Ticks::between($row->cron, $row->timezone, $row->lastTickAt, Ticks::mostRecent($row->cron, $row->timezone, $now), cap: 1);

            if ($standing !== []) {
                return $standing[0];
            }
        }

        return Ticks::nextAfter($row->cron, $row->timezone, $now);
    }
}
