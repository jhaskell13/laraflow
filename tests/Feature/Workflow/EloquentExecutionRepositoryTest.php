<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
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

        $step->markRunning(new DateTimeImmutable);
        $step->markCompleted(['customer_id' => 456], new DateTimeImmutable);

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

        $step->markRunning(new DateTimeImmutable);
        $this->repository()->saveStep($step);

        $step->markCompleted(['customer_id' => 456], new DateTimeImmutable);
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

        $step->markRunning(new DateTimeImmutable);
        $this->repository()->saveStep($step);

        // Another worker reclaims the execution while worker-a is mid-step.
        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['claim_token' => 'worker-b']);

        $step->markCompleted(['customer_id' => 456], new DateTimeImmutable);
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

        $execution->markRunning(new DateTimeImmutable);
        $execution->markCompleted(new DateTimeImmutable);

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

        $execution->markRunning(new DateTimeImmutable);
        $execution->markCompleted(new DateTimeImmutable);

        $this->expectException(ClaimLostException::class);

        $this->repository()->release($execution);
    }

    public function test_a_step_failure_timestamp_round_trips_through_the_database(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $execution = $this->repository()->create('W', steps: ['StepA']);
        [$step] = $this->repository()->steps($execution);

        $step->markRunning(Date::now()->toImmutable());
        $step->markFailed('boom', 'RuntimeException', Date::now()->toImmutable());
        $this->repository()->saveStep($step);

        [$reloaded] = $this->repository()->steps($execution);

        $this->assertNotNull($reloaded->failedAt, 'A failed step must record when it failed.');
        $this->assertSame('2026-10-22 10:00:00', $reloaded->failedAt->format('Y-m-d H:i:s'));
        $this->assertNull($reloaded->completedAt);

        // Completing it on retry clears the failure timestamp durably.
        Date::setTestNow('2026-10-22 10:05:00');
        $reloaded->markRunning(Date::now()->toImmutable());
        $reloaded->markCompleted(['ok' => true], Date::now()->toImmutable());
        $this->repository()->saveStep($reloaded);

        [$final] = $this->repository()->steps($execution);

        $this->assertNull($final->failedAt);
        $this->assertSame('2026-10-22 10:05:00', $final->completedAt?->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------
    // stale()
    // ------------------------------------------------------------------

    public function test_stale_finds_running_executions_with_old_or_missing_claims(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $old = $this->repository()->create('WorkflowA');
        $this->repository()->claim($old->id, 'dead-worker', 300);

        $unclaimed = $this->repository()->create('WorkflowB');
        WorkflowExecutionModel::query()->whereKey($unclaimed->id)->update([
            'status' => ExecutionStatus::Running->value,
            'claimed_at' => null,
        ]);

        Date::setTestNow('2026-10-22 10:10:00');

        $fresh = $this->repository()->create('WorkflowC');
        $this->repository()->claim($fresh->id, 'live-worker', 300);

        $stale = $this->repository()->stale($this->secondsAgo(300));

        $this->assertSame(
            [$old->id, $unclaimed->id],
            array_map(fn ($execution) => $execution->id, $stale),
        );
    }

    public function test_stale_uses_the_same_boundary_comparison_as_claim(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $execution = $this->repository()->create('WorkflowA');
        $this->repository()->claim($execution->id, 'worker', 300);

        // Exactly stale_after old: claim() would allow a takeover at this
        // instant, so stale() must report it too.
        Date::setTestNow('2026-10-22 10:05:00');

        $this->assertCount(1, $this->repository()->stale($this->secondsAgo(300)));
    }

    public function test_stale_ignores_pending_failed_and_completed_executions(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $this->repository()->create('PendingWorkflow');

        $failed = $this->repository()->create('FailedWorkflow');
        WorkflowExecutionModel::query()->whereKey($failed->id)->update([
            'status' => ExecutionStatus::Failed->value,
        ]);

        $completed = $this->repository()->create('CompletedWorkflow');
        WorkflowExecutionModel::query()->whereKey($completed->id)->update([
            'status' => ExecutionStatus::Completed->value,
        ]);

        Date::setTestNow('2026-10-22 11:00:00');

        $this->assertSame([], $this->repository()->stale($this->secondsAgo(300)));
    }

    public function test_stale_respects_the_limit_and_workflow_filter(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        foreach (['WorkflowA', 'WorkflowA', 'WorkflowB'] as $workflow) {
            $execution = $this->repository()->create($workflow);
            $this->repository()->claim($execution->id, 'dead-worker', 300);
        }

        Date::setTestNow('2026-10-22 10:10:00');

        $this->assertCount(2, $this->repository()->stale($this->secondsAgo(300), limit: 2));
        $this->assertCount(2, $this->repository()->stale($this->secondsAgo(300), workflow: 'WorkflowA'));
        $this->assertCount(1, $this->repository()->stale($this->secondsAgo(300), workflow: 'WorkflowB'));
        $this->assertCount(0, $this->repository()->stale($this->secondsAgo(300), workflow: 'WorkflowC'));
    }

    // ------------------------------------------------------------------
    // pendingBefore()
    // ------------------------------------------------------------------

    public function test_pending_before_finds_only_old_pending_executions(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $old = $this->repository()->create('WorkflowA');

        $running = $this->repository()->create('WorkflowB');
        $this->repository()->claim($running->id, 'worker', 300);

        Date::setTestNow('2026-10-22 10:10:00');

        $young = $this->repository()->create('WorkflowC');

        $found = $this->repository()->pendingBefore($this->secondsAgo(300));

        $this->assertSame(
            [$old->id],
            array_map(fn ($execution) => $execution->id, $found),
        );
        $this->assertNotContains($young->id, array_map(fn ($execution) => $execution->id, $found));
    }

    public function test_pending_before_respects_the_limit_and_workflow_filter(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $this->repository()->create('WorkflowA');
        $this->repository()->create('WorkflowA');
        $this->repository()->create('WorkflowB');

        Date::setTestNow('2026-10-22 10:10:00');

        $this->assertCount(2, $this->repository()->pendingBefore($this->secondsAgo(300), limit: 2));
        $this->assertCount(2, $this->repository()->pendingBefore($this->secondsAgo(300), workflow: 'WorkflowA'));
        $this->assertCount(1, $this->repository()->pendingBefore($this->secondsAgo(300), workflow: 'WorkflowB'));
    }

    protected function secondsAgo(int $seconds): DateTimeImmutable
    {
        return Date::now()->subSeconds($seconds)->toImmutable();
    }

    protected function repository(): ExecutionRepository
    {
        return app(ExecutionRepository::class);
    }
}
