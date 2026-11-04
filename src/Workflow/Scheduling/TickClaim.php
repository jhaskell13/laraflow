<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use DateTimeImmutable;

/**
 * @internal One instant the scheduler decided to claim, before the
 * claim: which tick, as what outcome, standing for how many unclaimed
 * instants. The plan half of plan-then-claim, so the policy arithmetic
 * is separable from the writes it drives.
 */
final readonly class TickClaim
{
    public function __construct(
        public DateTimeImmutable $tickAt,
        public TickOutcome $outcome,
        public int $missed,
        public ?DateTimeImmutable $missedFrom,
    ) {}
}
