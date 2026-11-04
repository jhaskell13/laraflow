<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Queue\WorkflowDispatcher;
use Psr\Clock\ClockInterface;

/**
 * @internal Use the laraflow:recover command; this class is its engine and
 * not yet covered by the compatibility policy.
 *
 * Finds recoverable executions and retries them within an explicit
 * bound. Four kinds are recognised, scanned in this order — stale
 * claims, due retries, due waits, lost pendings — under one shared
 * limit:
 *
 * - stale claim: a `running` execution whose claim has outlived
 *   `stale_after` — the worker died mid-run. Reported as `step_timeout`
 *   instead when the interrupted step had also passed its deadline.
 * - retry due: a `retrying` execution whose next_retry_at has arrived.
 *   This is the contractual delivery path for scheduled retries; the
 *   delayed queue job (retry.dispatch_delayed) is only the fast path.
 * - wait due: a `waiting` execution whose next event — a timed resume
 *   or a signal deadline — has arrived. Like due retries, this is the
 *   contractual delivery path; the delayed queue job is the fast path,
 *   and a recorded signal's dispatch is the signaller's.
 * - never dispatched: a `pending` execution older than a threshold — its
 *   queue dispatch was lost. Opt-in, because `pending` is also the state
 *   of work created by start() and deliberately not yet executed.
 *
 * The scan only reads. The abandonment bound applies to *interruption*
 * recovery — crash-loop protection — and is measured two ways against
 * the same number: the highest step attempt count, and the count of
 * consecutive claims that reached no step at all. The second exists
 * because a worker that faults between claiming and its first step
 * write never moves the first. Due retries are deliberately exempt:
 * their bound is the step's own retry budget, already enforced durably
 * by the runner via `attempts`, and applying the interruption bound to
 * them would strand every policy whose budget exceeds it.
 */
final class RecoveryService
{
    protected ClockInterface $clock;

    public function __construct(
        protected ExecutionRepository $repository,
        protected WorkflowDispatcher $dispatcher,
        protected WorkflowRunner $runner,
        protected WorkflowResolver $resolver,
        protected EventEmitter $events,
        ClockInterface $clock,
        protected int $redispatchAfterSeconds = 60,
    ) {
        $this->clock = $clock;

        if ($redispatchAfterSeconds < 0) {
            throw new InvalidArgumentException('laraflow.recovery.redispatch_after must not be negative.');
        }
    }

    /**
     * Find recoverable executions, most urgent first — stale claims,
     * then due retries, then due waits, then lost pendings — bounded by
     * $limit across all scans together. Makes no state changes.
     *
     * @param  int|null  $pendingAfterSeconds  Null skips the pending scan entirely.
     * @param  int  $abandonAfterAttempts  Interrupted candidates whose steps already
     *                                     reached this many attempts, or which were claimed this
     *                                     many times without reaching a step, are flagged abandoned.
     * @param  string|null  $workflow  Only executions created from this workflow class.
     * @param  bool  $includeDueRetries  Scan for scheduled retries and due waits.
     * @param  string|null  $tenantId  Only executions for this tenant — applied to
     *                                 every scan, so a tenant-scoped run cannot
     *                                 touch another tenant's work.
     * @return RecoveryCandidate[]
     */
    public function scan(
        int $staleAfterSeconds,
        ?int $pendingAfterSeconds,
        int $limit,
        int $abandonAfterAttempts,
        ?string $workflow = null,
        bool $includeDueRetries = true,
        ?string $tenantId = null,
    ): array {
        $now = $this->clock->now();
        $candidates = [];

        // A push younger than this is in flight; see recovery.redispatch_after.
        $availableBefore = $now->modify("-{$this->redispatchAfterSeconds} seconds");

        $stale = $this->repository->stale(
            $now->modify("-{$staleAfterSeconds} seconds"),
            $limit,
            $workflow,
            $tenantId,
        );

        foreach ($stale as $execution) {
            $steps = $this->repository->steps($execution);

            $candidates[] = $this->candidate(
                $execution,
                $this->staleReason($steps, $now),
                $abandonAfterAttempts,
                $steps,
            );
        }

        // Abandoned and unrecoverable candidates are reported, not acted
        // on; they stay in the stale set until an operator cancels or
        // re-runs them and must not starve the scans below of budget.
        // By reference, deliberately: an arrow function would capture
        // $candidates as it stood here — the stale set alone — and every
        // later scan would be budgeted against that instead of against
        // everything found so far, which is how this bound was once three
        // times the number it promised.
        $spent = function () use (&$candidates): int {
            return count(array_filter($candidates, fn (RecoveryCandidate $c): bool => $c->retryable()));
        };

        if ($includeDueRetries && ($remaining = $limit - $spent()) > 0) {
            foreach ($this->repository->dueForRetry($now, $remaining, $workflow, $tenantId, $availableBefore) as $execution) {
                $candidates[] = $this->candidate($execution, RecoveryReason::RetryDue, $abandonAfterAttempts);
            }
        }

        if ($includeDueRetries && ($remaining = $limit - $spent()) > 0) {
            foreach ($this->repository->waitingDue($now, $remaining, $workflow, $tenantId, $availableBefore) as $execution) {
                $candidates[] = $this->candidate($execution, RecoveryReason::WaitDue, $abandonAfterAttempts);
            }
        }

        if ($pendingAfterSeconds !== null && ($remaining = $limit - $spent()) > 0) {
            $pending = $this->repository->pendingBefore(
                $now->modify("-{$pendingAfterSeconds} seconds"),
                $remaining,
                $workflow,
                $tenantId,
                $availableBefore,
            );

            foreach ($pending as $execution) {
                $candidates[] = $this->candidate($execution, RecoveryReason::NeverDispatched, $abandonAfterAttempts);
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

        // Recovery never re-runs a terminal failure: a candidate that
        // failed between scan and run is refused, not resurrected.
        return $this->runner->execute($candidate->execution->id, $workflow, reexecuteFailed: false);
    }

    /**
     * The retry bounds are enforced here, not only in the command, so no
     * caller of the service can retry past them by accident. Deliberate
     * overrides go through laraflow:execute / WorkflowRunner::execute().
     */
    protected function assertRetryable(RecoveryCandidate $candidate): void
    {
        if ($candidate->retryable()) {
            return;
        }

        if ($candidate->workflowMissing) {
            throw new InvalidArgumentException(
                "Execution [{$candidate->execution->id}] was created from "
                ."[{$candidate->execution->workflow}], which no longer exists or is not a "
                .'workflow. Recovery cannot run it; restore the class or handle it manually.'
            );
        }

        if ($candidate->abandoned) {
            throw new InvalidArgumentException(
                "Execution [{$candidate->execution->id}] has {$candidate->abandonedBecause()} "
                .'and is abandoned by recovery. Re-run it deliberately with laraflow:execute.'
            );
        }
    }

    /**
     * A stale claim whose interrupted step had also passed its deadline
     * is a timeout, not plain worker loss: the recovering worker will
     * charge the attempt to the step's retry policy, and the report
     * should say so.
     */
    /**
     * @param  StepExecution[]  $steps
     */
    protected function staleReason(array $steps, DateTimeImmutable $now): RecoveryReason
    {
        foreach ($steps as $step) {
            if ($step->isRunning() && $step->timeoutAt !== null && $step->timeoutAt <= $now) {
                return RecoveryReason::StepTimeout;
            }
        }

        return RecoveryReason::StaleClaim;
    }

    /**
     * The abandonment bound applies to interruptions only. Scheduled
     * work — a due retry, a due wait — is exempt: its budget is the
     * step's own policy, which the runner enforces, and holding it to
     * the interruption bound would strand every policy whose budget
     * exceeds it. The reason knows which it is.
     *
     * @param  StepExecution[]|null  $steps  Already loaded by the caller, or null to load.
     */
    protected function candidate(
        WorkflowExecution $execution,
        RecoveryReason $reason,
        int $abandonAfterAttempts,
        ?array $steps = null,
    ): RecoveryCandidate {
        $attempts = 0;

        foreach ($steps ?? $this->repository->steps($execution) as $step) {
            $attempts = max($attempts, $step->attempts);
        }

        return new RecoveryCandidate(
            execution: $execution,
            reason: $reason,
            attempts: $attempts,
            abandoned: $reason->isInterruption()
                && ($attempts >= $abandonAfterAttempts
                    || $execution->fruitlessClaims >= $abandonAfterAttempts),
            // A retry of a missing class fails before any step runs, so
            // attempts never grow and the bound above would never engage —
            // the scheduler would re-queue it forever. Flag it instead.
            workflowMissing: ! class_exists($execution->workflow)
                || ! is_subclass_of($execution->workflow, Workflow::class),
            fruitlessClaims: $execution->fruitlessClaims,
        );
    }

    protected function emitRetryScheduled(RecoveryCandidate $candidate): void
    {
        $this->events->emit(new WorkflowExecutionRetryScheduled(
            executionId: $candidate->execution->id,
            workflow: $candidate->execution->workflow,
            correlationId: $candidate->execution->correlationId,
            tenantId: $candidate->execution->tenantId,
            reason: $candidate->reason,
            attempts: $candidate->attempts,
        ));
    }
}
