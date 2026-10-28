<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * This step's last attempt failed and another is scheduled, due at
     * `next_retry_at`.
     */
    case Retrying = 'retrying';

    /**
     * A conditional step whose condition answered false: durably
     * recorded, attempt-free, and treated like `completed` by recovery
     * — the decision replays from the record, never re-evaluates.
     */
    case Skipped = 'skipped';

    /**
     * Parked on a Wait returned by handle(): a timed resume instant in
     * `next_retry_at`, or a signal named in `wait_signal` with an
     * optional deadline in `timeout_at`. Resuming completes the step.
     */
    case Waiting = 'waiting';
}
