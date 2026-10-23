<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

enum ExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * A step attempt failed and the step's retry policy scheduled
     * another, due at `next_retry_at`. Distinct from Failed so that
     * alerting on failed executions stays meaningful once retries are
     * enabled: an execution that will heal itself is not a failure.
     */
    case Retrying = 'retrying';

    /** Terminal: cancelled by the application or an operator. */
    case Cancelled = 'cancelled';
}
