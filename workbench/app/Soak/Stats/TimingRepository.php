<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Stats;

use Closure;
use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\TenantThrottledException;
use Laraflow\Workflow\ChildProgress;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionPage;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\RetentionTier;
use Laraflow\Workflow\SignalReceipt;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;
use Throwable;

/**
 * The real repository, timed.
 *
 * Wraps whatever ExecutionRepository the container holds and records how
 * long the writes on the hot path take and how each claim ended — which
 * is where "claim contention" and "checkpoint latency" in the soak's
 * report come from. Everything else is delegated untouched, so the
 * protocol under test is exactly the one an application runs.
 */
final class TimingRepository implements ExecutionRepository
{
    public function __construct(
        private readonly ExecutionRepository $inner,
        private readonly StatsSink $stats,
    ) {}

    public function find(int $id): WorkflowExecution
    {
        return $this->inner->find($id);
    }

    public function steps(WorkflowExecution $execution): array
    {
        return $this->inner->steps($execution);
    }

    public function attempts(StepExecution $step): array
    {
        return $this->inner->attempts($step);
    }

    public function attemptHistory(WorkflowExecution $execution): array
    {
        return $this->inner->attemptHistory($execution);
    }

    public function list(ExecutionFilter $filter, int $limit = 50, ?string $cursor = null): ExecutionPage
    {
        return $this->inner->list($filter, $limit, $cursor);
    }

    public function count(ExecutionFilter $filter): int
    {
        return $this->inner->count($filter);
    }

    public function stepProgress(array $ids): array
    {
        return $this->inner->stepProgress($ids);
    }

    public function childProgress(int $parentStepExecutionId): ChildProgress
    {
        return $this->inner->childProgress($parentStepExecutionId);
    }

    public function create(string $workflow, array $input = [], array $steps = [], ?ExecutionOptions $options = null, int $planVersion = 1): WorkflowExecution
    {
        return $this->timed('create', fn (): WorkflowExecution => $this->inner->create($workflow, $input, $steps, $options, $planVersion));
    }

    public function rewritePlan(WorkflowExecution $execution, int $frozenBefore, array $tail, int $planVersion): array
    {
        return $this->inner->rewritePlan($execution, $frozenBefore, $tail, $planVersion);
    }

    public function claim(int $id, string $claimToken, int $staleAfterSeconds, bool $ignoreRetrySchedule = false, bool $reexecuteFailed = false): WorkflowExecution
    {
        return $this->timed('claim', fn (): WorkflowExecution => $this->inner->claim($id, $claimToken, $staleAfterSeconds, $ignoreRetrySchedule, $reexecuteFailed));
    }

    public function appendCompensation(WorkflowExecution $execution, StepExecution $compensates): StepExecution
    {
        return $this->inner->appendCompensation($execution, $compensates);
    }

    public function checkpointStatus(WorkflowExecution $execution): void
    {
        $this->inner->checkpointStatus($execution);
    }

    public function childOfStep(int $parentStepExecutionId): ?WorkflowExecution
    {
        return $this->inner->childOfStep($parentStepExecutionId);
    }

    public function createMember(int $parentStepExecutionId, int $index, Closure $create): mixed
    {
        return $this->inner->createMember($parentStepExecutionId, $index, $create);
    }

    public function stale(DateTimeImmutable $claimedBefore, int $limit = 100, ?string $workflow = null, ?string $tenantId = null, ?DateTimeImmutable $availableBefore = null): array
    {
        return $this->inner->stale($claimedBefore, $limit, $workflow, $tenantId, $availableBefore);
    }

    public function pendingBefore(DateTimeImmutable $createdBefore, int $limit = 100, ?string $workflow = null, ?string $tenantId = null, ?DateTimeImmutable $availableBefore = null): array
    {
        return $this->inner->pendingBefore($createdBefore, $limit, $workflow, $tenantId, $availableBefore);
    }

    public function dueForRetry(DateTimeImmutable $dueBefore, int $limit = 100, ?string $workflow = null, ?string $tenantId = null, ?DateTimeImmutable $availableBefore = null): array
    {
        return $this->inner->dueForRetry($dueBefore, $limit, $workflow, $tenantId, $availableBefore);
    }

    public function recordSignal(int $id, string $signal, array $payload, DateTimeImmutable $at): SignalReceipt
    {
        return $this->timed('signal', fn (): SignalReceipt => $this->inner->recordSignal($id, $signal, $payload, $at));
    }

    public function park(WorkflowExecution $execution, StepExecution $step, ?DateTimeImmutable $nextEventAt): ?array
    {
        return $this->timed('park', fn (): ?array => $this->inner->park($execution, $step, $nextEventAt));
    }

    public function deferred(ExecutionFilter $filter, DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->inner->deferred($filter, $now, $limit);
    }

    public function retriesDue(ExecutionFilter $filter, DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->inner->retriesDue($filter, $now, $limit);
    }

    public function waitsDue(ExecutionFilter $filter, DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->inner->waitsDue($filter, $now, $limit);
    }

    public function countAttention(ExecutionFilter $filter, DateTimeImmutable $now): array
    {
        return $this->inner->countAttention($filter, $now);
    }

    public function countByWorkflowAndStatus(ExecutionFilter $filter): array
    {
        return $this->inner->countByWorkflowAndStatus($filter);
    }

    public function waitingDue(DateTimeImmutable $dueBefore, int $limit = 100, ?string $workflow = null, ?string $tenantId = null, ?DateTimeImmutable $availableBefore = null): array
    {
        return $this->inner->waitingDue($dueBefore, $limit, $workflow, $tenantId, $availableBefore);
    }

    public function saveStep(WorkflowExecution $execution, StepExecution $step, ?StepAttempt $attempt = null): void
    {
        $this->timed('save_step', function () use ($execution, $step, $attempt): bool {
            $this->inner->saveStep($execution, $step, $attempt);

            return true;
        });
    }

    public function checkpoint(WorkflowExecution $execution, StepExecution $step, ?StepAttempt $attempt = null): void
    {
        $this->timed('checkpoint', function () use ($execution, $step, $attempt): bool {
            $this->inner->checkpoint($execution, $step, $attempt);

            return true;
        });
    }

    public function retentionCandidates(RetentionTier $tier, array $statuses, DateTimeImmutable $finishedBefore, int $limit, ?string $workflow = null, ?string $tenantId = null): array
    {
        return $this->inner->retentionCandidates($tier, $statuses, $finishedBefore, $limit, $workflow, $tenantId);
    }

    public function scrubPayloads(array $ids, array $statuses, DateTimeImmutable $finishedBefore, DateTimeImmutable $scrubbedAt): int
    {
        return $this->inner->scrubPayloads($ids, $statuses, $finishedBefore, $scrubbedAt);
    }

    public function pruneAttempts(array $ids, array $statuses, DateTimeImmutable $finishedBefore): array
    {
        return $this->inner->pruneAttempts($ids, $statuses, $finishedBefore);
    }

    public function deleteExecutions(array $ids, array $statuses, DateTimeImmutable $finishedBefore): int
    {
        return $this->inner->deleteExecutions($ids, $statuses, $finishedBefore);
    }

    public function recordDispatch(int $id, DateTimeImmutable $dispatchedAt, ?DateTimeImmutable $availableAt = null): void
    {
        $this->inner->recordDispatch($id, $dispatchedAt, $availableAt);
    }

    public function requestCancellation(int $id, ?string $reason, DateTimeImmutable $at, ?DateTimeImmutable $staleBefore = null): WorkflowExecution
    {
        return $this->inner->requestCancellation($id, $reason, $at, $staleBefore);
    }

    public function release(WorkflowExecution $execution): void
    {
        $this->timed('release', function () use ($execution): bool {
            $this->inner->release($execution);

            return true;
        });
    }

    /**
     * Run one repository call, record how long it took and how it
     * ended, and let its result or its exception through unchanged.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function timed(string $op, Closure $call): mixed
    {
        $started = hrtime(true);

        try {
            $result = $call();
            $this->stats->record($op, 'ok', $this->elapsed($started));

            return $result;
        } catch (Throwable $exception) {
            $this->stats->record($op, self::outcome($exception), $this->elapsed($started), ['error' => $exception::class]);

            throw $exception;
        }
    }

    private function elapsed(int|float $startedNs): float
    {
        return round((hrtime(true) - $startedNs) / 1_000_000, 3);
    }

    /**
     * The designed refusals, named, so the report can tell contention
     * from a tenant ceiling from a genuine error.
     */
    private static function outcome(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ExecutionAlreadyClaimedException => 'already_claimed',
            $exception instanceof ClaimLostException => 'claim_lost',
            $exception instanceof TenantThrottledException => 'throttled',
            $exception instanceof RetryNotDueException => 'not_due',
            $exception instanceof ExecutionStillWaitingException => 'still_waiting',
            $exception instanceof ExecutionNotRunnableException => 'not_runnable',
            $exception instanceof SignalRefusedException => 'signal_refused',
            default => 'error',
        };
    }
}
