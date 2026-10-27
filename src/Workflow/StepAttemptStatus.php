<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * Outcome of one finished step attempt, as recorded in the append-only
 * attempt history.
 */
enum StepAttemptStatus: string
{
    case Completed = 'completed';

    /** The attempt threw. */
    case Failed = 'failed';

    /**
     * The attempt's deadline (the step's timeout_at) passed and its
     * worker's claim went stale; classified by the recovering worker.
     */
    case TimedOut = 'timed_out';

    /**
     * The worker died mid-attempt before any deadline passed. Side
     * effects may or may not have happened — this is the at-least-once
     * boundary, closed out in history when the step is re-run.
     */
    case Interrupted = 'interrupted';
}
