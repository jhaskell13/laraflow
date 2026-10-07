<?php

namespace Laraflow\Workflow\Repositories;

use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\ExecutionStatus;

class EloquentExecutionRepository implements ExecutionRepository
{
    public function create(
        string $workflow,
        array $input = []
    ): WorkflowExecution {
        $model = WorkflowExecutionModel::query()->create([
            'workflow' => $workflow,
            'status' => ExecutionStatus::Pending->value,
            'input' => $input,
        ]);

        return $this->toDomain($model);
    }

    public function find(
        int|string $id
    ): WorkflowExecution {
        $model = WorkflowExecutionModel::query()->findOrFail($id);

        return $this->toDomain($model);
    }

    public function save(
        WorkflowExecution $execution
    ): void {
        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update([
                'workflow' => $execution->workflow,
                'status' => $execution->status->value,
                'input' => $execution->input,
                'started_at' => $execution->startedAt,
                'completed_at' => $execution->completedAt,
                'failed_at' => $execution->failedAt,
            ]);
    }

    public function createStep(
        WorkflowExecution $execution,
        string $step,
        int $position,
        mixed $input = null
    ): StepExecution {
        $model = StepExecutionModel::query()->create([
            'workflow_execution_id' => $execution->id,
            'step' => $step,
            'position' => $position,
            'status' => StepStatus::Pending->value,
            'input' => $input,
            'attempts' => 0,
        ]);

        return $this->stepToDomain($model);
    }

    public function steps(
        WorkflowExecution $execution
    ): array {
        return WorkflowExecutionModel::query()
            ->findOrFail($execution->id)
            ->steps()
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
                'input' => $step->input,
                'output' => $step->output,
                'error' => $step->error,
                'started_at' => $step->startedAt,
                'completed_at' => $step->completedAt,
                'next_retry_at' => $step->nextRetryAt,
            ]);
    }

    protected function toDomain(
        WorkflowExecutionModel $model
    ): WorkflowExecution {
        return new WorkflowExecution(
            id: $model->id,
            workflow: $model->workflow,
            input: $model->input ?? [],
            status: ExecutionStatus::from($model->status),
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
            startedAt: $model->started_at?->toImmutable(),
            completedAt: $model->completed_at?->toImmutable(),
            nextRetryAt: $model->next_retry_at?->toImmutable(),
        );
    }
}