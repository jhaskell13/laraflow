<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use DateTimeImmutable;
use Laraflow\Workflow\MissedTickPolicy;

/**
 * Read-only handle describing one schedule row. The row is a cache of
 * the declaring class, synchronised on every tick run; what only the
 * row knows is `lastTickAt` — the high-water mark before which nothing
 * is due — and whether an operator paused it. See docs/schedules.md.
 */
final readonly class ScheduledWorkflow
{
    public function __construct(
        public int $id,
        public string $workflow,
        public string $cron,
        public string $timezone,
        public MissedTickPolicy $missedTickPolicy,
        public bool $withoutOverlapping,
        public ?string $tenantId,
        public ?DateTimeImmutable $lastTickAt,
        public ?DateTimeImmutable $pausedAt,
        public ?DateTimeImmutable $declaredAt,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isPaused(): bool
    {
        return $this->pausedAt !== null;
    }
}
