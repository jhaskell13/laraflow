<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use DateTimeImmutable;

/**
 * Read-only record of one claimed tick: the instant it names, what
 * became of it, and the execution it started — by id, not by reference,
 * because the execution may be deleted by retention long before the
 * tick history is and the record stays true (docs/schedules.md,
 * "Persistence").
 */
final readonly class ScheduleTick
{
    /**
     * @param  int  $missed  Due instants that passed unclaimed before this
     *                       tick; 0 when none were.
     * @param  DateTimeImmutable|null  $missedFrom  The earliest of them.
     * @param  DateTimeImmutable|null  $createdAt  When a scheduler claimed the
     *                                             row, as distinct from the instant it names; the difference
     *                                             is scheduler lag.
     */
    public function __construct(
        public int $id,
        public int $scheduleId,
        public DateTimeImmutable $tickAt,
        public TickOutcome $outcome,
        public ?int $workflowExecutionId,
        public int $missed,
        public ?DateTimeImmutable $missedFrom,
        public ?int $overlappedExecutionId,
        public ?DateTimeImmutable $createdAt,
    ) {}
}
