<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * What a schedule does about ticks that passed while no scheduler was
 * running (docs/schedules.md, "Missed ticks").
 *
 * Skip is the default because the catch-up storm is the failure mode
 * that gets noticed: a per-minute job catching up an hour's outage is
 * sixty executions landing on a queue that was already behind. CatchUp
 * is for work that is *about* its tick — a daily settlement, a monthly
 * statement — and is bounded by `laraflow.schedules.max_catch_up` per
 * run and `laraflow.schedules.catch_up_window` overall.
 */
enum MissedTickPolicy: string
{
    /**
     * Run only the most recent due tick. The instants between the last
     * claimed tick and it are not run; the fired tick's row counts them.
     */
    case Skip = 'skip';

    /**
     * Claim and run the missed instants oldest first, one execution
     * each, each told its own tick instant.
     */
    case CatchUp = 'catch_up';
}
