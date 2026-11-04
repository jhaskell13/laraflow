<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use DateTimeImmutable;

/**
 * @internal One thing the scheduler did (or, dry, would do) to one
 * schedule on one run — a row of the tick run's report.
 */
final readonly class ScheduleAction
{
    /** The current due tick was claimed and its execution created. */
    public const STARTED = 'started';

    /** A missed tick was claimed under MissedTickPolicy::CatchUp. */
    public const CAUGHT_UP = 'caught_up';

    /** The tick was claimed and no execution started (overlap). */
    public const SKIPPED_OVERLAP = 'skipped_overlap';

    /** Nothing at or before now that last_tick_at has not covered. */
    public const NOT_DUE = 'not_due';

    /** Another scheduler claimed the tick first — the designed race. */
    public const TAKEN = 'taken';

    /** The schedule is paused and names no tick. */
    public const PAUSED = 'paused';

    /** The declaration is unusable, or the start threw; detail says why. */
    public const FAILED = 'failed';

    /** Dry run: the tick that would have been claimed. */
    public const WOULD_START = 'would_start';

    public function __construct(
        public string $workflow,
        public string $outcome,
        public ?DateTimeImmutable $tickAt = null,
        public ?int $executionId = null,
        public int $missed = 0,
        public ?int $overlappedExecutionId = null,
        public ?string $delivery = null,
        public ?string $detail = null,
    ) {}

    public function failed(): bool
    {
        return $this->outcome === self::FAILED;
    }

    public function claimed(): bool
    {
        return in_array($this->outcome, [self::STARTED, self::CAUGHT_UP, self::SKIPPED_OVERLAP], true);
    }
}
