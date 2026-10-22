<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories;

use Closure;
use Illuminate\Support\Facades\Date;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class EloquentExecutionRepository implements ExecutionRepository
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $steps
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = []
    ): WorkflowExecution {
        $model = $this->transaction(function () use ($workflow, $input, $steps) {
            $model = WorkflowExecutionModel::query()->create([
                'workflow' => $workflow,
                'status' => ExecutionStatus::Pending->value,
                'input' => $input,
                'context' => [],
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
        int $staleAfterSeconds
    ): WorkflowExecution {
        $now = Date::now();
        $staleBefore = $now->copy()->subSeconds($staleAfterSeconds);

        $claimed = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where(function ($query) use ($staleBefore) {
                $query
                    ->whereIn('status', [
                        ExecutionStatus::Pending->value,
                        ExecutionStatus::Failed->value,
                    ])
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
        StepExecution $step
    ): void {
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
                'next_retry_at' => $step->nextRetryAt,
            ]);
    }

    public function checkpoint(
        WorkflowExecution $execution,
        StepExecution $step
    ): void {
        $this->transaction(function () use ($execution, $step) {
            $this->saveStep($step);

            $updated = WorkflowExecutionModel::query()
                ->whereKey($execution->id)
                ->where('claim_token', $execution->claimToken)
                ->update([
                    'context' => $execution->context,
                ]);

            if ($updated === 0) {
                // Rolls back the step write: a worker without the claim
                // must not persist anything.
                throw ClaimLostException::forId($execution->id);
            }
        });
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
                'claim_token' => null,
                'claimed_at' => null,
            ]);

        if ($updated === 0) {
            throw ClaimLostException::forId($execution->id);
        }
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

        throw ExecutionNotRunnableException::forStatus($id, $status);
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
            nextRetryAt: $model->next_retry_at?->toImmutable(),
        );
    }
}
