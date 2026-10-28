<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use DateTimeImmutable;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionPage;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\RetentionTier;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;

/**
 * Decorator that simulates a worker process dying at a precise persistence
 * boundary. From the moment of "death" every further repository call throws,
 * exactly as if the process had been killed: nothing after the boundary is
 * persisted, including failure records.
 */
class CrashingExecutionRepository implements ExecutionRepository
{
    protected bool $dead = false;

    /** @var array<string, int> */
    protected array $calls = [];

    public function __construct(
        protected ExecutionRepository $inner,
        protected string $dieOnMethod,
        protected int $dieOnCall = 1,
        protected string $phase = 'before',
    ) {}

    public function create(string $workflow, array $input = [], array $steps = [], ?ExecutionOptions $options = null): WorkflowExecution
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->create($workflow, $input, $steps, $options));
    }

    public function find(int|string $id): WorkflowExecution
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->find($id));
    }

    public function claim(int|string $id, string $claimToken, int $staleAfterSeconds, bool $ignoreRetrySchedule = false): WorkflowExecution
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->claim($id, $claimToken, $staleAfterSeconds, $ignoreRetrySchedule));
    }

    public function steps(WorkflowExecution $execution): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->steps($execution));
    }

    public function list(ExecutionFilter $filter, int $limit = 50, ?string $cursor = null): ExecutionPage
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->list($filter, $limit, $cursor));
    }

    public function count(ExecutionFilter $filter): int
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->count($filter));
    }

    public function stepProgress(array $ids): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->stepProgress($ids));
    }

    public function attemptHistory(WorkflowExecution $execution): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->attemptHistory($execution));
    }

    public function stale(DateTimeImmutable $claimedBefore, int $limit = 100, ?string $workflow = null): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->stale($claimedBefore, $limit, $workflow));
    }

    public function pendingBefore(DateTimeImmutable $createdBefore, int $limit = 100, ?string $workflow = null): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->pendingBefore($createdBefore, $limit, $workflow));
    }

    public function dueForRetry(DateTimeImmutable $dueBefore, int $limit = 100, ?string $workflow = null, ?string $tenantId = null): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->dueForRetry($dueBefore, $limit, $workflow, $tenantId));
    }

    public function saveStep(WorkflowExecution $execution, StepExecution $step, ?StepAttempt $attempt = null): void
    {
        $this->call(__FUNCTION__, function () use ($execution, $step, $attempt) {
            $this->inner->saveStep($execution, $step, $attempt);
        });
    }

    public function checkpoint(WorkflowExecution $execution, StepExecution $step, ?StepAttempt $attempt = null): void
    {
        $this->call(__FUNCTION__, function () use ($execution, $step, $attempt) {
            $this->inner->checkpoint($execution, $step, $attempt);
        });
    }

    public function retentionCandidates(RetentionTier $tier, array $statuses, DateTimeImmutable $finishedBefore, int $limit, ?string $workflow = null, ?string $tenantId = null): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->retentionCandidates($tier, $statuses, $finishedBefore, $limit, $workflow, $tenantId));
    }

    public function scrubPayloads(array $ids, array $statuses, DateTimeImmutable $finishedBefore, DateTimeImmutable $scrubbedAt): int
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->scrubPayloads($ids, $statuses, $finishedBefore, $scrubbedAt));
    }

    public function pruneAttempts(array $ids, array $statuses, DateTimeImmutable $finishedBefore): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->pruneAttempts($ids, $statuses, $finishedBefore));
    }

    public function deleteExecutions(array $ids, array $statuses, DateTimeImmutable $finishedBefore): int
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->deleteExecutions($ids, $statuses, $finishedBefore));
    }

    public function recordDispatch(int|string $id, DateTimeImmutable $dispatchedAt, ?DateTimeImmutable $availableAt = null): void
    {
        $this->call(__FUNCTION__, function () use ($id, $dispatchedAt, $availableAt) {
            $this->inner->recordDispatch($id, $dispatchedAt, $availableAt);
        });
    }

    public function requestCancellation(int|string $id, ?string $reason, DateTimeImmutable $at): WorkflowExecution
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->requestCancellation($id, $reason, $at));
    }

    public function attempts(StepExecution $step): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->attempts($step));
    }

    public function release(WorkflowExecution $execution): void
    {
        $this->call(__FUNCTION__, function () use ($execution) {
            $this->inner->release($execution);
        });
    }

    protected function call(string $method, callable $delegate): mixed
    {
        if ($this->dead) {
            throw new SimulatedWorkerCrash('The worker is gone.');
        }

        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;

        $boundary = $method === $this->dieOnMethod
            && $this->calls[$method] === $this->dieOnCall;

        if ($boundary && $this->phase === 'before') {
            $this->dead = true;

            throw new SimulatedWorkerCrash("Worker died before [{$method}] call #{$this->dieOnCall}.");
        }

        $result = $delegate();

        if ($boundary && $this->phase === 'after') {
            $this->dead = true;

            throw new SimulatedWorkerCrash("Worker died after [{$method}] call #{$this->dieOnCall}.");
        }

        return $result;
    }
}
