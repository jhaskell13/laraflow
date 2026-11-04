<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

/**
 * What became of a claimed tick — decided in the transaction that
 * claimed it and never revisited, which is why a tick row has no
 * updated_at (docs/schedules.md, "Persistence").
 */
enum TickOutcome: string
{
    /** The current due instant: the tick started its execution. */
    case Started = 'started';

    /**
     * A missed instant run by MissedTickPolicy::CatchUp, told its own
     * tick rather than the clock's.
     */
    case CaughtUp = 'caught_up';

    /**
     * Claimed, and no execution started: the schedule runs
     * withoutOverlapping() and the previous tick's execution was still
     * unfinished. The row names it.
     */
    case SkippedOverlap = 'skipped_overlap';
}
