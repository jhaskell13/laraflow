<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * One interrupted execution found by a recovery scan, together with the
 * policy decision made about it.
 *
 * `attempts` is the highest attempt count among the execution's steps —
 * the durable measure of how often this execution has already been run.
 * When it reaches the recovery attempt bound the candidate is `abandoned`:
 * recovery will report it but refuse to retry it, so a crash-looping
 * workflow cannot be re-dispatched forever by a scheduler. Abandoned work
 * can still be re-run deliberately via laraflow:execute.
 */
readonly class RecoveryCandidate
{
    /**
     * @param  string  $reason  A WorkflowExecutionRetryScheduled::REASON_* value.
     * @param  int  $attempts  Highest step attempt count, 0 if nothing ran yet.
     */
    public function __construct(
        public WorkflowExecution $execution,
        public string $reason,
        public int $attempts,
        public bool $abandoned,
    ) {}
}
