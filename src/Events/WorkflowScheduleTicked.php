<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * A schedule's tick was claimed and the execution it starts durably
 * created, in the one transaction that did both. Fired after that
 * commit; the execution's input is never on the event.
 */
final readonly class WorkflowScheduleTicked
{
    /**
     * @param  int  $missed  Due instants that passed unclaimed before this
     *                       tick, counted on it (docs/schedules.md, "Missed ticks").
     * @param  bool  $caughtUp  Whether this tick was run by the CatchUp
     *                          policy rather than being the current due instant.
     */
    public function __construct(
        public int $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public DateTimeImmutable $tickAt,
        public int $missed,
        public bool $caughtUp,
    ) {}
}
