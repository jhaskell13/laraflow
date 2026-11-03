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

    /**
     * Parked on a step's Wait, claim released. `next_retry_at` holds
     * the next instant anything can happen — the resume time for a
     * timed wait, the deadline for a bounded signal wait, null for an
     * indefinite one, which only a signal makes claimable again.
     */
    case Waiting = 'waiting';

    /** Terminal: cancelled by the application or an operator. */
    case Cancelled = 'cancelled';

    /**
     * The execution failed terminally and is undoing the steps that
     * completed, in reverse (docs/advanced-workflows.md). Unfinished
     * and claimable: a worker that dies mid-pass leaves a stale claim
     * the stale scan reclaims, and the pass resumes from the record.
     * The resting state afterwards is always `failed` — compensation
     * changes what was left behind, not whether the execution failed.
     */
    case Compensating = 'compensating';

    /**
     * Whether the execution has stopped and recorded `finished_at`.
     *
     * Deliberately **not** called `isTerminal()`. In Laraflow "terminal"
     * means *never claimable again* — `completed` and `cancelled` — and
     * `failed` is not terminal, because re-executing it is the documented
     * recovery path. But a failed execution has finished: it is not
     * running, nothing is scheduled, and it carries a `finished_at`.
     *
     * This is the question retention asks ("has this stopped?"), and
     * conflating it with claimability would let a retention policy
     * either skip failed work forever or delete work a worker owns.
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Pending, self::Running, self::Retrying, self::Waiting, self::Compensating => false,
        };
    }
}
