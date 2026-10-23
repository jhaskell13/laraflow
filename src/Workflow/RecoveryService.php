<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use InvalidArgumentException;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Clock;
use Psr\Clock\ClockInterface;

/**
 * @internal Use the laraflow:recover command; this class is its engine and
 * not yet covered by the compatibility policy.
 *
 * Finds interrupted executions and retries them within an explicit bound.
 * Two kinds of interruption are recognised:
 *
 * - stale claim: a `running` execution whose claim has outlived
 *   `stale_after` — the worker died mid-run.
 * - never dispatched: a `pending` execution older than a threshold — its
 *   queue dispatch was lost. Opt-in, because `pending` is also the state
 *   of work created by start() and deliberately not yet executed.
 *
 * The scan only reads; the retry bound (`abandoned` candidates are
 * refused) and the per-scan limit keep every recovery action finite.
 */
class RecoveryService
{
    protected ClockInterface $clock;

    public function __construct(
        protected ExecutionRepository $repository,
        protected WorkflowDispatcher $dispatcher,
        protected WorkflowRunner $runner,
        protected WorkflowResolver $resolver,
        protected ?EventEmitter $events = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new Clock;
    }

    /**
     * Find interrupted executions, oldest first, bounded by $limit across
     * both scans together. Makes no state changes.
     *
     * @param  int|null  $pendingAfterSeconds  Null skips the pending scan entirely.
     * @param  int  $maxAttempts  Candidates whose steps already reached this many attempts are flagged abandoned.
     * @return RecoveryCandidate[]
     */
    public function scan(
        int $staleAfterSeconds,
        ?int $pendingAfterSeconds,
        int $limit,
        int $maxAttempts,
        ?string $workflow = null,
    ): array {
        $now = $this->clock->now();
        $candidates = [];

        $stale = $this->repository->stale(
            $now->modify("-{$staleAfterSeconds} seconds"),
            $limit,
            $workflow,
        );

        foreach ($stale as $execution) {
            $candidates[] = $this->candidate(
                $execution,
                WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM,
                $maxAttempts,
            );
        }

        $remaining = $limit - count($candidates);

        if ($pendingAfterSeconds !== null && $remaining > 0) {
            $pending = $this->repository->pendingBefore(
                $now->modify("-{$pendingAfterSeconds} seconds"),
                $remaining,
                $workflow,
            );

            foreach ($pending as $execution) {
                $candidates[] = $this->candidate(
                    $execution,
                    WorkflowExecutionRetryScheduled::REASON_NEVER_DISPATCHED,
                    $maxAttempts,
                );
            }
        }

        return $candidates;
    }

    /**
     * Queue the candidate for re-execution. The claim still decides which
     * worker runs it, so racing the original (live) worker is safe.
     *
     * @throws ExecutionNotRunnableException when the execution completed between scan and dispatch.
     */
    public function dispatch(RecoveryCandidate $candidate): void
    {
        $this->assertRetryable($candidate);

        $this->dispatcher->dispatchExecution($candidate->execution->id);

        $this->emitRetryScheduled($candidate);
    }

    /**
     * Run the candidate in this process instead of queueing it.
     *
     * @throws WorkflowDefinitionMismatchException when the recorded workflow class is gone.
     * @throws ExecutionAlreadyClaimedException when a live worker claimed it between scan and run.
     * @throws ExecutionNotRunnableException when it completed between scan and run.
     */
    public function runInline(RecoveryCandidate $candidate): WorkflowExecution
    {
        $this->assertRetryable($candidate);

        $workflow = $this->resolver->resolve(
            $candidate->execution->id,
            $candidate->execution->workflow,
        );

        $this->emitRetryScheduled($candidate);

        return $this->runner->execute($candidate->execution->id, $workflow);
    }

    /**
     * The retry bounds are enforced here, not only in the command, so no
     * caller of the service can retry past them by accident. Deliberate
     * overrides go through laraflow:execute / WorkflowRunner::execute().
     */
    protected function assertRetryable(RecoveryCandidate $candidate): void
    {
        if ($candidate->workflowMissing) {
            throw new InvalidArgumentException(
                "Execution [{$candidate->execution->id}] was created from "
                ."[{$candidate->execution->workflow}], which no longer exists or is not a "
                .'workflow. Recovery cannot run it; restore the class or handle it manually.'
            );
        }

        if ($candidate->abandoned) {
            throw new InvalidArgumentException(
                "Execution [{$candidate->execution->id}] has reached {$candidate->attempts} step "
                .'attempts and is abandoned by recovery. Re-run it deliberately with laraflow:execute.'
            );
        }
    }

    protected function candidate(
        WorkflowExecution $execution,
        string $reason,
        int $maxAttempts,
    ): RecoveryCandidate {
        $attempts = 0;

        foreach ($this->repository->steps($execution) as $step) {
            $attempts = max($attempts, $step->attempts);
        }

        return new RecoveryCandidate(
            execution: $execution,
            reason: $reason,
            attempts: $attempts,
            abandoned: $attempts >= $maxAttempts,
            // A retry of a missing class fails before any step runs, so
            // attempts never grow and the bound above would never engage —
            // the scheduler would re-queue it forever. Flag it instead.
            workflowMissing: ! class_exists($execution->workflow)
                || ! is_subclass_of($execution->workflow, Workflow::class),
        );
    }

    protected function emitRetryScheduled(RecoveryCandidate $candidate): void
    {
        $this->events?->emit(new WorkflowExecutionRetryScheduled(
            executionId: $candidate->execution->id,
            workflow: $candidate->execution->workflow,
            reason: $candidate->reason,
            attempts: $candidate->attempts,
        ));
    }
}
