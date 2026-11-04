<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * A schedule's tick was claimed and no execution started: the schedule
 * runs withoutOverlapping() and the previous tick's execution was still
 * unfinished. Fired after the skip's durable write; carries no
 * correlation id because no execution was created to carry one.
 */
final readonly class WorkflowScheduleSkipped
{
    /**
     * @param  int  $overlappedExecutionId  The still-unfinished execution
     *                                      the schedule declined to run beside.
     */
    public function __construct(
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public DateTimeImmutable $tickAt,
        public int $overlappedExecutionId,
    ) {}
}
