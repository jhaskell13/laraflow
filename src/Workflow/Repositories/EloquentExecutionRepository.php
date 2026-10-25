<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories;

use Closure;
use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Support\Clock;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Clock\ClockInterface;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class EloquentExecutionRepository implements ExecutionRepository
{
    // The contract is deliberately one interface — see
    // docs/public-api.md — but its 22 methods answer four different
    // questions. Grouping the implementation by question keeps each
    // group's shared invariants next to the methods that rely on them.
    use Concerns\AppliesRetention;
    use Concerns\QueriesExecutions;
    use Concerns\ScansForRecovery;

    protected ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new Clock;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $steps
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = [],
        ?ExecutionOptions $options = null
    ): WorkflowExecution {
        $model = $this->transaction(function () use ($workflow, $input, $steps, $options) {
            $model = WorkflowExecutionModel::query()->create([
                'workflow' => $workflow,
                'status' => ExecutionStatus::Pending->value,
                'input' => $input,
                'context' => [],
                'correlation_id' => $options?->correlationId,
                'tenant_id' => $options?->tenantId,
            ]);

            foreach ($steps as $position => $stepClass) {
                $model->steps()->create([
                    'step' => $stepClass,
                    'position' => $position,
                    'status' => StepStatus::Pending->value,
                    'attempts' => 0,
                ]);
            }

            return $model;
        });

        return $this->toDomain($model);
    }

    public function find(
        int|string $id
    ): WorkflowExecution {
        $model = WorkflowExecutionModel::query()->find($id);

        if ($model === null) {
            throw ExecutionNotFoundException::forId($id);
        }

        return $this->toDomain($model);
    }

    public function claim(
        int|string $id,
        string $claimToken,
        int $staleAfterSeconds,
        bool $ignoreRetrySchedule = false
    ): WorkflowExecution {
        $now = $this->clock->now();
        $staleBefore = $now->modify("-{$staleAfterSeconds} seconds");

        $claimed = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where(function ($query) use ($staleBefore, $now, $ignoreRetrySchedule) {
                $query
                    ->whereIn('status', [
                        ExecutionStatus::Pending->value,
                        ExecutionStatus::Failed->value,
                    ])
                    ->orWhere(function ($query) use ($now, $ignoreRetrySchedule) {
                        $query->where('status', ExecutionStatus::Retrying->value);

                        if (! $ignoreRetrySchedule) {
                            // Claimable only once its backoff has elapsed;
                            // early claiming is the deliberate operator
                            // override. A null due time (which the runner
                            // never writes) counts as due, because a
                            // schedule nobody can read must not make an
                            // execution unclaimable forever.
                            $query->where(function ($query) use ($now) {
                                $query
                                    ->whereNull('next_retry_at')
                                    ->orWhere('next_retry_at', '<=', $now);
                            });
                        }
                    })
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query
                            ->where('status', ExecutionStatus::Running->value)
                            ->where(function ($query) use ($staleBefore) {
                                $query
                                    ->whereNull('claimed_at')
                                    ->orWhere('claimed_at', '<=', $staleBefore);
                            });
                    });
            })
            ->update([
                'status' => ExecutionStatus::Running->value,
                'claim_token' => $claimToken,
                'claimed_at' => $now,
                // Claiming means this is running now, so any previously
                // scheduled retry is spent. Mirrors the same invariant
                // in WorkflowExecution::markRunning(); without it a
                // running row would keep advertising a past due time.
                'next_retry_at' => null,
                // Mirrors markRunning(): a re-executed failure is not
                // finished, and must not look eligible to retention
                // while a worker is actively running it. The recorded
                // duration goes with it — it described a run that this
                // claim is superseding.
                'finished_at' => null,
                'duration_ms' => null,
            ]);

        if ($claimed === 0) {
            $this->explainFailedClaim($id);
        }

        // Record the first start without disturbing the original timestamp
        // on re-execution; guarded by the claim we just took.
        WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where('claim_token', $claimToken)
            ->whereNull('started_at')
            ->update(['started_at' => $now]);

        return $this->find($id);
    }

    public function steps(
        WorkflowExecution $execution
    ): array {
        return StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->orderBy('position')
            ->get()
            ->map(fn (StepExecutionModel $model) => $this->stepToDomain($model))
            ->all();
    }

    public function saveStep(
        StepExecution $step,
        ?StepAttempt $attempt = null
    ): void {
        if ($attempt !== null) {
            $this->transaction(function () use ($step, $attempt) {
                $this->writeStep($step);
                $this->appendAttempt($attempt);
            });

            return;
        }

        $this->writeStep($step);
    }

    public function checkpoint(
        WorkflowExecution $execution,
        StepExecution $step,
        ?StepAttempt $attempt = null
    ): void {
        $this->transaction(function () use ($execution, $step, $attempt) {
            $this->writeStep($step);

            if ($attempt !== null) {
                $this->appendAttempt($attempt);
            }

            $updated = WorkflowExecutionModel::query()
                ->whereKey($execution->id)
                ->where('claim_token', $execution->claimToken)
                ->update([
                    'context' => $execution->context,
                ]);

            if ($updated === 0) {
                // Rolls back the step write and the attempt row with it:
                // a worker without the claim must not persist anything.
                throw ClaimLostException::forId($execution->id);
            }
        });
    }

    public function recordDispatch(
        int|string $id,
        DateTimeImmutable $dispatchedAt,
        ?DateTimeImmutable $availableAt = null
    ): void {
        $updated = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->update([
                'dispatched_at' => $dispatchedAt,
                // A delivery cannot be available before it was dispatched;
                // a caller passing an earlier instant meant "now".
                'available_at' => $availableAt !== null && $availableAt > $dispatchedAt
                    ? $availableAt
                    : $dispatchedAt,
            ]);

        if ($updated === 0) {
            throw ExecutionNotFoundException::forId($id);
        }
    }

    public function requestCancellation(
        int|string $id,
        ?string $reason,
        DateTimeImmutable $at
    ): WorkflowExecution {
        // Unclaimed work is cancelled outright. The status predicate is
        // the same compare-and-set claiming uses, so this cannot race a
        // worker: whichever UPDATE lands first, the other is refused.
        $cancelled = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->whereIn('status', [
                ExecutionStatus::Pending->value,
                ExecutionStatus::Failed->value,
                ExecutionStatus::Retrying->value,
            ])
            ->update([
                'status' => ExecutionStatus::Cancelled->value,
                'cancelled_at' => $at,
                'cancel_requested_at' => $at,
                'cancel_reason' => $reason,
                // Cancellation is terminal, so it finishes the execution
                // — the same write markCancelled() makes on the handle.
                'finished_at' => $at,
                // A cancelled execution must never surface in the scan.
                'next_retry_at' => null,
                'claim_token' => null,
                'claimed_at' => null,
            ]);

        if ($cancelled === 1) {
            return $this->find($id);
        }

        // A live worker owns it: leave a request for its next step
        // boundary rather than rewriting state underneath it.
        $requested = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where('status', ExecutionStatus::Running->value)
            ->update([
                'cancel_requested_at' => $at,
                'cancel_reason' => $reason,
            ]);

        if ($requested === 1) {
            return $this->find($id);
        }

        $current = $this->find($id);

        if ($current->isCancelled()) {
            return $current;
        }

        throw ExecutionNotCancellableException::forStatus($id, $current->status);
    }

    public function release(
        WorkflowExecution $execution
    ): void {
        $updated = WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->where('claim_token', $execution->claimToken)
            ->update([
                'status' => $execution->status->value,
                'context' => $execution->context,
                'error' => $execution->error,
                'error_class' => $execution->errorClass,
                'completed_at' => $execution->completedAt,
                'failed_at' => $execution->failedAt,
                'finished_at' => $execution->finishedAt,
                'duration_ms' => $execution->durationMs(),
                'next_retry_at' => $execution->nextRetryAt,
                'cancel_requested_at' => $execution->cancelRequestedAt,
                'cancelled_at' => $execution->cancelledAt,
                'cancel_reason' => $execution->cancelReason,
                'claim_token' => null,
                'claimed_at' => null,
            ]);

        if ($updated === 0) {
            throw ClaimLostException::forId($execution->id);
        }
    }

    protected function writeStep(StepExecution $step): void
    {
        StepExecutionModel::query()
            ->whereKey($step->id)
            ->update([
                'status' => $step->status->value,
                'attempts' => $step->attempts,
                'output' => $step->output,
                'error' => $step->error,
                'error_class' => $step->errorClass,
                'started_at' => $step->startedAt,
                'completed_at' => $step->completedAt,
                'failed_at' => $step->failedAt,
                'timeout_at' => $step->timeoutAt,
                'next_retry_at' => $step->nextRetryAt,
            ]);
    }

    protected function appendAttempt(StepAttempt $attempt): void
    {
        StepAttemptModel::query()->create([
            'workflow_execution_id' => $attempt->executionId,
            'step_execution_id' => $attempt->stepExecutionId,
            'attempt' => $attempt->attempt,
            'status' => $attempt->status->value,
            'error' => $attempt->error,
            'error_class' => $attempt->errorClass,
            'started_at' => $attempt->startedAt,
            'ended_at' => $attempt->endedAt,
            'duration_ms' => $attempt->durationMs(),
            'next_retry_at' => $attempt->nextRetryAt,
        ]);
    }

    protected function explainFailedClaim(int|string $id): never
    {
        $current = WorkflowExecutionModel::query()->find($id);

        if ($current === null) {
            throw ExecutionNotFoundException::forId($id);
        }

        $status = ExecutionStatus::from($current->status);

        if ($status === ExecutionStatus::Running) {
            throw ExecutionAlreadyClaimedException::forId($id);
        }

        if ($status === ExecutionStatus::Retrying) {
            // The only way a retrying claim misses: it is not due yet.
            throw RetryNotDueException::forId($id, $current->next_retry_at?->toImmutable());
        }

        throw ExecutionNotRunnableException::forStatus($id, $status);
    }

    protected function attemptToDomain(StepAttemptModel $model): StepAttempt
    {
        return new StepAttempt(
            executionId: $model->workflow_execution_id,
            stepExecutionId: $model->step_execution_id,
            attempt: $model->attempt,
            status: StepAttemptStatus::from($model->status),
            error: $model->error,
            errorClass: $model->error_class,
            startedAt: $model->started_at?->toImmutable(),
            endedAt: $model->ended_at?->toImmutable(),
            nextRetryAt: $model->next_retry_at?->toImmutable(),
        );
    }

    protected function transaction(Closure $callback): mixed
    {
        return WorkflowExecutionModel::query()
            ->getConnection()
            ->transaction($callback);
    }

    protected function toDomain(
        WorkflowExecutionModel $model
    ): WorkflowExecution {
        return new WorkflowExecution(
            id: $model->id,
            workflow: $model->workflow,
            input: $model->input ?? [],
            status: ExecutionStatus::from($model->status),
            context: $model->context ?? [],
            error: $model->error,
            errorClass: $model->error_class,
            claimToken: $model->claim_token,
            claimedAt: $model->claimed_at?->toImmutable(),
            createdAt: $model->created_at?->toImmutable(),
            startedAt: $model->started_at?->toImmutable(),
            completedAt: $model->completed_at?->toImmutable(),
            failedAt: $model->failed_at?->toImmutable(),
            nextRetryAt: $model->next_retry_at?->toImmutable(),
            cancelRequestedAt: $model->cancel_requested_at?->toImmutable(),
            cancelledAt: $model->cancelled_at?->toImmutable(),
            cancelReason: $model->cancel_reason,
            correlationId: $model->correlation_id,
            tenantId: $model->tenant_id,
            dispatchedAt: $model->dispatched_at?->toImmutable(),
            availableAt: $model->available_at?->toImmutable(),
            finishedAt: $model->finished_at?->toImmutable(),
            payloadsScrubbedAt: $model->payloads_scrubbed_at?->toImmutable(),
            persistedDurationMs: $model->duration_ms,
        );
    }

    protected function stepToDomain(
        StepExecutionModel $model
    ): StepExecution {
        return new StepExecution(
            id: $model->id,
            executionId: $model->workflow_execution_id,
            step: $model->step,
            position: $model->position,
            status: StepStatus::from($model->status),
            attempts: $model->attempts,
            input: $model->input,
            output: $model->output,
            error: $model->error,
            errorClass: $model->error_class,
            startedAt: $model->started_at?->toImmutable(),
            completedAt: $model->completed_at?->toImmutable(),
            failedAt: $model->failed_at?->toImmutable(),
            timeoutAt: $model->timeout_at?->toImmutable(),
            nextRetryAt: $model->next_retry_at?->toImmutable(),
        );
    }
}
