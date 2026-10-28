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
}
