<?php

namespace Laraflow\Tests\Feature\Workflow;

use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;

class EloquentExecutionRepositoryTest extends TestCase
{
    protected function repository(): ExecutionRepository
    {
        return app(ExecutionRepository::class);
    }

    public function test_it_creates_an_execution_with_its_full_step_plan_atomically(): void
    {
        $execution = $this->repository()->create(
            workflow: 'SyncCustomerWorkflow',
            input: ['customer_id' => 123],
            steps: ['LoadCustomer', 'SyncWithCrm'],
        );

        $this->assertNotNull($execution->id);
        $this->assertSame('SyncCustomerWorkflow', $execution->workflow);
        $this->assertSame(['customer_id' => 123], $execution->input);
        $this->assertSame(ExecutionStatus::Pending, $execution->status);
        $this->assertSame([], $execution->context);

        $steps = $this->repository()->steps($execution);

        $this->assertCount(2, $steps);
        $this->assertSame('LoadCustomer', $steps[0]->step);
        $this->assertSame(0, $steps[0]->position);
        $this->assertSame('SyncWithCrm', $steps[1]->step);
        $this->assertSame(1, $steps[1]->position);
        $this->assertSame(StepStatus::Pending, $steps[0]->status);
        $this->assertSame(0, $steps[0]->attempts);
    }

    public function test_find_throws_a_package_exception_for_unknown_executions(): void
    {
        $this->expectException(ExecutionNotFoundException::class);

        $this->repository()->find(42);
    }

    public function test_claiming_a_pending_execution_marks_it_running_and_owned(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');

        $claimed = $this->repository()->claim($execution->id, 'worker-a', 300);

        $this->assertSame(ExecutionStatus::Running, $claimed->status);
        $this->assertSame('worker-a', $claimed->claimToken);
        $this->assertNotNull($claimed->claimedAt);
        $this->assertNotNull($claimed->startedAt);
    }

    public function test_only_one_worker_can_claim_an_execution(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');

        $this->repository()->claim($execution->id, 'worker-a', 300);

        $this->expectException(ExecutionAlreadyClaimedException::class);

        $this->repository()->claim($execution->id, 'worker-b', 300);
    }

    public function test_a_stale_claim_can_be_taken_over_and_keeps_the_original_started_at(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');
        $first = $this->repository()->claim($execution->id, 'worker-a', 300);

        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['claimed_at' => now()->subMinutes(30)]);

        $reclaimed = $this->repository()->claim($execution->id, 'worker-b', 300);

        $this->assertSame('worker-b', $reclaimed->claimToken);
        $this->assertEquals($first->startedAt, $reclaimed->startedAt);
    }

    public function test_a_failed_execution_can_be_claimed_again(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');

        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['status' => ExecutionStatus::Failed->value]);

        $claimed = $this->repository()->claim($execution->id, 'worker-a', 300);

        $this->assertSame(ExecutionStatus::Running, $claimed->status);
    }

    public function test_a_completed_execution_cannot_be_claimed(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');

        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['status' => ExecutionStatus::Completed->value]);

        $this->expectException(ExecutionNotRunnableException::class);

        $this->repository()->claim($execution->id, 'worker-a', 300);
    }

    public function test_claiming_an_unknown_execution_throws_not_found(): void
    {
        $this->expectException(ExecutionNotFoundException::class);

        $this->repository()->claim(42, 'worker-a', 300);
    }

    public function test_it_persists_step_changes(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['CreateCustomer']);
        [$step] = $this->repository()->steps($execution);

        $step->markRunning(new \DateTimeImmutable());
        $step->markCompleted(['customer_id' => 456], new \DateTimeImmutable());

        $this->repository()->saveStep($step);

        [$retrieved] = $this->repository()->steps($execution);

        $this->assertSame(StepStatus::Completed, $retrieved->status);
        $this->assertSame(1, $retrieved->attempts);
        $this->assertSame(['customer_id' => 456], $retrieved->output);
        $this->assertNotNull($retrieved->completedAt);
    }

    public function test_checkpoint_persists_step_and_context_together(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['CreateCustomer']);
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);
        [$step] = $this->repository()->steps($execution);

        $step->markRunning(new \DateTimeImmutable());
        $this->repository()->saveStep($step);

        $step->markCompleted(['customer_id' => 456], new \DateTimeImmutable());
        $execution->context = ['customer_id' => 456];

        $this->repository()->checkpoint($execution, $step);

        $this->assertSame(
            ['customer_id' => 456],
            WorkflowExecutionModel::query()->first()->context
        );
        $this->assertSame(
            StepStatus::Completed->value,
            StepExecutionModel::query()->first()->status
        );
    }

    public function test_checkpoint_without_the_claim_persists_nothing(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['CreateCustomer']);
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);
        [$step] = $this->repository()->steps($execution);

        $step->markRunning(new \DateTimeImmutable());
        $this->repository()->saveStep($step);

        // Another worker reclaims the execution while worker-a is mid-step.
        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['claim_token' => 'worker-b']);

        $step->markCompleted(['customer_id' => 456], new \DateTimeImmutable());
        $execution->context = ['customer_id' => 456];

        try {
            $this->repository()->checkpoint($execution, $step);
            $this->fail('Expected ClaimLostException.');
        } catch (ClaimLostException) {
            // Expected.
        }

        // The whole checkpoint rolled back: not even the step row changed.
        $this->assertSame(
            StepStatus::Running->value,
            StepExecutionModel::query()->first()->status
        );
        $this->assertSame([], WorkflowExecutionModel::query()->first()->context);
    }

    public function test_release_persists_the_terminal_state_and_clears_the_claim(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);

        $execution->markRunning(new \DateTimeImmutable());
        $execution->markCompleted(new \DateTimeImmutable());

        $this->repository()->release($execution);

        $model = WorkflowExecutionModel::query()->first();

        $this->assertSame(ExecutionStatus::Completed->value, $model->status);
        $this->assertNotNull($model->completed_at);
        $this->assertNull($model->claim_token);
        $this->assertNull($model->claimed_at);
    }

    public function test_release_without_the_claim_throws(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);

        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['claim_token' => 'worker-b']);

        $execution->markRunning(new \DateTimeImmutable());
        $execution->markCompleted(new \DateTimeImmutable());

        $this->expectException(ClaimLostException::class);

        $this->repository()->release($execution);
    }
}
