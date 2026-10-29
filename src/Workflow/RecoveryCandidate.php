<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * One interrupted execution found by a recovery scan, together with the
 * policy decision made about it.
 *
 * `attempts` is the highest attempt count among the execution's steps —
 * the durable measure of how often this execution has already been run.
 * `fruitlessClaims` is how many consecutive claims reached no step at
 * all — the durable measure of a fault *before* the first step, which
 * `attempts` cannot see. When either reaches the recovery bound the
 * candidate is `abandoned`: recovery will report it but refuse to retry
 * it, so a crash-looping workflow cannot be re-dispatched forever by a
 * scheduler. Abandoned work can still be re-run deliberately via
 * laraflow:execute.
 *
 * @internal Produced and consumed by RecoveryService; applications read
 *           recovery results through the command or the lifecycle events.
 */
readonly class RecoveryCandidate
{
    /**
     * @param  string  $reason  A WorkflowExecutionRetryScheduled::REASON_* value.
     * @param  int  $attempts  Highest step attempt count, 0 if nothing ran yet.
     * @param  bool  $workflowMissing  The recorded workflow class no longer exists
     *                                 (or is not a workflow), so no retry can run it.
     * @param  int  $fruitlessClaims  Consecutive claims that ended before any step write.
     */
    public function __construct(
        public WorkflowExecution $execution,
        public string $reason,
        public int $attempts,
        public bool $abandoned,
        public bool $workflowMissing = false,
        public int $fruitlessClaims = 0,
    ) {}

    /**
     * Which measure abandoned this candidate, for a report. Both are
     * held to the same bound, so whichever is larger is the one that
     * reached it; on a tie the step attempts are named, because they
     * are what an operator can read off the step table.
     */
    public function abandonedBecause(): string
    {
        if ($this->attempts >= $this->fruitlessClaims) {
            return "reached {$this->attempts} step attempts";
        }

        return "was claimed {$this->fruitlessClaims} times without reaching a step";
    }

    /**
     * Whether recovery is allowed to retry this candidate at all.
     *
     * A missing workflow class matters especially because such a retry
     * fails *before* any step runs: attempts never increment, so the
     * attempt bound alone would never stop the scheduler from re-queueing
     * it forever.
     */
    public function retryable(): bool
    {
        return ! $this->abandoned && ! $this->workflowMissing;
    }
}
