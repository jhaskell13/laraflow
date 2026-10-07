<?php

namespace Laraflow\Tests\Feature\Workflow;

use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\StepStatus;

class EloquentExecutionRepositoryTest extends TestCase
{
    public function test_it_creates_and_retrieves_a_workflow_execution(): void
    {
        $repository = app(ExecutionRepository::class);

        $execution = $repository->create(
            workflow: 'SyncCustomerWorkflow',
            input: ['customer_id' => 123],
        );

        $this->assertNotNull($execution->id);
        $this->assertSame(
            'SyncCustomerWorkflow',
            $execution->workflow
        );
        $this->assertSame(
            ['customer_id' => 123],
            $execution->input
        );
        $this->assertSame(
            ExecutionStatus::Pending,
            $execution->status
        );

        $retrieved = $repository->find($execution->id);

        $this->assertSame($execution->id, $retrieved->id);
        $this->assertSame(
            $execution->workflow,
            $retrieved->workflow
        );
        $this->assertSame(
            $execution->input,
            $retrieved->input
        );
        $this->assertSame(
            ExecutionStatus::Pending,
            $retrieved->status
        );
    }

    public function test_it_persists_workflow_execution_changes(): void
    {
        $repository = app(ExecutionRepository::class);

        $execution = $repository->create(
            workflow: 'SyncCustomerWorkflow'
        );

        $execution->status = ExecutionStatus::Running;
        $execution->startedAt = new \DateTimeImmutable();

        $repository->save($execution);

        $retrieved = $repository->find($execution->id);

        $this->assertSame(
            ExecutionStatus::Running,
            $retrieved->status
        );
        $this->assertNotNull($retrieved->startedAt);
    }

    public function test_it_creates_and_retrieves_steps(): void
    {
        $repository = app(ExecutionRepository::class);

        $execution = $repository->create(
            workflow: 'SyncCustomerWorkflow'
        );

        $step = $repository->createStep(
            execution: $execution,
            step: 'CreateCustomer',
            position: 0,
            input: ['email' => 'test@example.com']
        );

        $this->assertNotNull($step->id);
        $this->assertSame($execution->id, $step->executionId);
        $this->assertSame('CreateCustomer', $step->step);
        $this->assertSame(0, $step->position);
        $this->assertSame(
            StepStatus::Pending,
            $step->status
        );
        $this->assertSame(
            ['email' => 'test@example.com'],
            $step->input
        );

        $steps = $repository->steps($execution);

        $this->assertCount(1, $steps);
        $this->assertSame($step->id, $steps[0]->id);
    }

    public function test_it_persists_step_changes(): void
    {
        $repository = app(ExecutionRepository::class);

        $execution = $repository->create(
            workflow: 'SyncCustomerWorkflow'
        );

        $step = $repository->createStep(
            execution: $execution,
            step: 'CreateCustomer',
            position: 0,
        );

        $step->status = StepStatus::Completed;
        $step->attempts = 1;
        $step->output = ['customer_id' => 456];
        $step->completedAt = new \DateTimeImmutable();

        $repository->saveStep($step);

        $steps = $repository->steps($execution);

        $retrieved = $steps[0];

        $this->assertSame(
            StepStatus::Completed,
            $retrieved->status
        );
        $this->assertSame(1, $retrieved->attempts);
        $this->assertSame(
            ['customer_id' => 456],
            $retrieved->output
        );
        $this->assertNotNull($retrieved->completedAt);
    }
}