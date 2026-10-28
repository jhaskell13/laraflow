<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;

class EloquentExecutionRepositoryTest extends TestCase
{
    public function test_it_creates_an_execution_with_its_full_step_plan_atomically(): void
    {
        $execution = $this->repository()->create(
            workflow: 'SyncCustomerWorkflow',
            input: ['customer_id' => 123],
            steps: ['LoadCustomer', 'SyncWithCrm'],
        );

        // Not assertNotNull: the id is typed non-null, so that would
        // pass without the row ever having been given one.
        $this->assertNotEmpty($execution->id);
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
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);
        [$step] = $this->repository()->steps($execution);

        $step->markRunning(new DateTimeImmutable);
        $step->markCompleted(['customer_id' => 456], new DateTimeImmutable);

        $this->repository()->saveStep($execution, $step);

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
        $this->repository()->saveStep($execution, $step);

        $step->markCompleted(['customer_id' => 456], new DateTimeImmutable);
        $execution->context = ['customer_id' => 456];

        $this->repository()->checkpoint($execution, $step);

        $this->assertSame(
            ['customer_id' => 456],
            WorkflowExecutionModel::query()->firstOrFail()->context
        );
        $this->assertSame(
            StepStatus::Completed->value,
            StepExecutionModel::query()->firstOrFail()->status
        );
    }

    public function test_checkpoint_without_the_claim_persists_nothing(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['CreateCustomer']);
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);
        [$step] = $this->repository()->steps($execution);

        $step->markRunning(new DateTimeImmutable);
        $this->repository()->saveStep($execution, $step);

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
            StepExecutionModel::query()->firstOrFail()->status
        );
        $this->assertSame([], WorkflowExecutionModel::query()->firstOrFail()->context);
    }

    public function test_save_step_without_the_claim_persists_nothing(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['CreateCustomer']);
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);
        [$step] = $this->repository()->steps($execution);

        $step->markRunning(new DateTimeImmutable('2026-10-28 09:00:00'));
        $this->repository()->saveStep($execution, $step);

        // worker-a stalls past stale_after; worker-b reclaims the execution.
        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['claim_token' => 'worker-b']);

        // worker-a wakes and tries to record its step's failure — a step
        // row write with an attempt row, the shape every terminal
        // outcome takes.
        $step->markFailed('boom', 'RuntimeException', new DateTimeImmutable('2026-10-28 09:00:05'));

        try {
            $this->repository()->saveStep($execution, $step, new StepAttempt(
                executionId: $execution->id,
                stepExecutionId: $step->id,
                attempt: 1,
                status: StepAttemptStatus::Failed,
                error: 'boom',
                errorClass: 'RuntimeException',
            ));
            $this->fail('Expected ClaimLostException.');
        } catch (ClaimLostException) {
            // Expected.
        }

        // Rolled back as one: the step row still reads as worker-b
        // found it, and no attempt row from the dead worker exists.
        $row = StepExecutionModel::query()->firstOrFail();
        $this->assertSame(StepStatus::Running->value, $row->status);
        $this->assertNull($row->failed_at);
        $this->assertNull($row->error);
        $this->assertSame(0, StepAttemptModel::query()->count());
        $this->assertSame('worker-b', WorkflowExecutionModel::query()->firstOrFail()->claim_token);
    }

    public function test_save_step_under_the_claim_does_not_disturb_the_execution_row(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['CreateCustomer']);
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);
        [$step] = $this->repository()->steps($execution);

        $before = WorkflowExecutionModel::query()->firstOrFail();

        $step->markRunning(new DateTimeImmutable);
        $this->repository()->saveStep($execution, $step);

        // The guard touches the executions row to verify the claim, and
        // must touch nothing else there: the context is checkpoint's to
        // write, the claim stays exactly as taken.
        $after = WorkflowExecutionModel::query()->firstOrFail();
        $this->assertSame($before->version + 1, $after->version);
        $this->assertSame($before->context, $after->context);
        $this->assertSame($before->status, $after->status);
        $this->assertSame('worker-a', $after->claim_token);
        $this->assertSame($before->claimed_at?->getTimestamp(), $after->claimed_at?->getTimestamp());
    }

    public function test_release_persists_the_terminal_state_and_clears_the_claim(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow');
        $execution = $this->repository()->claim($execution->id, 'worker-a', 300);

        $execution->markRunning(new DateTimeImmutable);
        $execution->markCompleted(new DateTimeImmutable);

        $this->repository()->release($execution);

        $model = WorkflowExecutionModel::query()->firstOrFail();

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

    // ------------------------------------------------------------------
    // Claiming retrying executions
    // ------------------------------------------------------------------

    public function test_a_retrying_execution_cannot_be_claimed_before_it_is_due(): void
    {
        $execution = $this->makeRetryingExecution(dueAt: '2026-10-22 10:05:00', now: '2026-10-22 10:00:00');

        try {
            $this->repository()->claim($execution->id, 'eager-worker', 300);
            $this->fail('Expected RetryNotDueException.');
        } catch (RetryNotDueException $exception) {
            // The refusal names the due time and the override.
            $this->assertStringContainsString('2026-10-22 10:05:00', $exception->getMessage());
            $this->assertStringContainsString('--now', $exception->getMessage());
        }

        $this->assertSame(
            ExecutionStatus::Retrying->value,
            WorkflowExecutionModel::query()->whereKey($execution->id)->value('status'),
            'A refused claim must not disturb the schedule.',
        );
    }

    public function test_a_retrying_execution_is_claimable_once_due(): void
    {
        $execution = $this->makeRetryingExecution(dueAt: '2026-10-22 10:05:00', now: '2026-10-22 10:00:00');

        Date::setTestNow('2026-10-22 10:05:00');

        $claimed = $this->repository()->claim($execution->id, 'patient-worker', 300);

        $this->assertSame(ExecutionStatus::Running, $claimed->status);
        $this->assertSame('patient-worker', $claimed->claimToken);
        $this->assertNull($claimed->nextRetryAt, 'Claiming consumes the schedule.');
    }

    public function test_the_override_claims_a_retrying_execution_early(): void
    {
        $execution = $this->makeRetryingExecution(dueAt: '2026-10-22 11:00:00', now: '2026-10-22 10:00:00');

        $claimed = $this->repository()->claim($execution->id, 'operator', 300, ignoreRetrySchedule: true);

        $this->assertSame(ExecutionStatus::Running, $claimed->status);
    }

    public function test_only_one_worker_can_claim_a_due_retry(): void
    {
        $execution = $this->makeRetryingExecution(dueAt: '2026-10-22 10:00:00', now: '2026-10-22 10:00:00');

        $this->repository()->claim($execution->id, 'worker-a', 300);

        $this->expectException(ExecutionAlreadyClaimedException::class);

        $this->repository()->claim($execution->id, 'worker-b', 300);
    }

    public function test_a_cancelled_execution_cannot_be_claimed(): void
    {
        $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'status' => ExecutionStatus::Cancelled->value,
        ]);

        try {
            $this->repository()->claim($execution->id, 'worker', 300);
            $this->fail('Expected ExecutionNotRunnableException.');
        } catch (ExecutionNotRunnableException $exception) {
            $this->assertStringContainsString('cancelled', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // dueForRetry()
    // ------------------------------------------------------------------

    public function test_due_for_retry_finds_only_retries_whose_time_has_come(): void
    {
        $due = $this->makeRetryingExecution(dueAt: '2026-10-22 10:04:00', now: '2026-10-22 10:00:00');
        $overdue = $this->makeRetryingExecution(dueAt: '2026-10-22 10:01:00', now: '2026-10-22 10:00:00');
        $this->makeRetryingExecution(dueAt: '2026-10-22 11:30:00', now: '2026-10-22 10:00:00');

        Date::setTestNow('2026-10-22 10:05:00');

        $found = $this->repository()->dueForRetry(Date::now()->toImmutable());

        // Most overdue first: the longest-waiting retry runs first.
        $this->assertSame(
            [$overdue->id, $due->id],
            array_map(fn ($execution) => $execution->id, $found),
        );
    }

    public function test_due_for_retry_ignores_other_statuses_whatever_their_next_retry_at_says(): void
    {
        // A stale next_retry_at on a non-retrying row (however it got
        // there) must never resurrect the execution through this scan.
        foreach ([ExecutionStatus::Running, ExecutionStatus::Completed, ExecutionStatus::Failed] as $status) {
            $execution = $this->repository()->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);

            WorkflowExecutionModel::query()->whereKey($execution->id)->update([
                'status' => $status->value,
                'next_retry_at' => '2026-10-22 09:00:00',
            ]);
        }

        Date::setTestNow('2026-10-22 10:00:00');

        $this->assertSame([], $this->repository()->dueForRetry(Date::now()->toImmutable()));
    }

    public function test_due_for_retry_respects_the_limit_and_workflow_filter(): void
    {
        $first = $this->makeRetryingExecution(dueAt: '2026-10-22 10:01:00', now: '2026-10-22 10:00:00');
        $second = $this->makeRetryingExecution(dueAt: '2026-10-22 10:02:00', now: '2026-10-22 10:00:00');
        $other = $this->makeRetryingExecution(dueAt: '2026-10-22 10:03:00', now: '2026-10-22 10:00:00', workflow: 'OtherWorkflow');

        Date::setTestNow('2026-10-22 10:30:00');

        $limited = $this->repository()->dueForRetry(Date::now()->toImmutable(), limit: 1);
        $this->assertSame([$first->id], array_map(fn ($execution) => $execution->id, $limited));

        $filtered = $this->repository()->dueForRetry(Date::now()->toImmutable(), workflow: 'OtherWorkflow');
        $this->assertSame([$other->id], array_map(fn ($execution) => $execution->id, $filtered));
    }

    // ------------------------------------------------------------------
    // Retry and cancellation fields round-trip
    // ------------------------------------------------------------------

    public function test_a_scheduled_retry_round_trips_through_the_database(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);
        $execution = $repository->claim($execution->id, 'worker', 300);

        $failedAt = new DateTimeImmutable('2026-10-23 10:00:00');
        $nextRetryAt = new DateTimeImmutable('2026-10-23 10:00:30');

        $step = $repository->steps($execution)[0];
        $step->markRunning($failedAt, new DateTimeImmutable('2026-10-23 10:00:10'));
        $step->markRetrying('boom', 'RuntimeException', $failedAt, $nextRetryAt);
        $repository->saveStep($execution, $step);

        $execution->markRetrying('boom', 'RuntimeException', $nextRetryAt);
        $repository->release($execution);

        $reloaded = $repository->find($execution->id);

        $this->assertTrue($reloaded->isRetrying());
        $this->assertSame('2026-10-23 10:00:30', $reloaded->nextRetryAt?->format('Y-m-d H:i:s'));
        $this->assertSame('boom', $reloaded->error);
        $this->assertNull($reloaded->failedAt);
        $this->assertNull($reloaded->claimToken, 'A retrying execution waits unclaimed.');

        $reloadedStep = $repository->steps($reloaded)[0];

        $this->assertTrue($reloadedStep->isRetrying());
        $this->assertSame('2026-10-23 10:00:00', $reloadedStep->failedAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 10:00:10', $reloadedStep->timeoutAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 10:00:30', $reloadedStep->nextRetryAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_cancellation_round_trips_through_the_database(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);
        $execution = $repository->claim($execution->id, 'worker', 300);

        $execution->markCancelled(new DateTimeImmutable('2026-10-23 11:00:00'), 'operator request');
        $repository->release($execution);

        $reloaded = $repository->find($execution->id);

        $this->assertTrue($reloaded->isCancelled());
        $this->assertSame('2026-10-23 11:00:00', $reloaded->cancelledAt?->format('Y-m-d H:i:s'));
        $this->assertSame('operator request', $reloaded->cancelReason);
        $this->assertNull($reloaded->claimToken);
    }

    public function test_a_failed_step_records_when_it_failed(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);
        $execution = $repository->claim($execution->id, 'worker', 300);

        $step = $repository->steps($execution)[0];
        $step->markRunning(new DateTimeImmutable('2026-10-23 12:00:00'));
        $step->markFailed('boom', 'RuntimeException', new DateTimeImmutable('2026-10-23 12:00:05'));
        $repository->saveStep($execution, $step);

        $this->assertSame(
            '2026-10-23 12:00:05',
            StepExecutionModel::query()->sole()->failed_at?->format('Y-m-d H:i:s'),
        );
    }

    // ------------------------------------------------------------------
    // finished_at: the single terminal timestamp retention scans on
    // ------------------------------------------------------------------

    public function test_every_terminal_release_persists_finished_at(): void
    {
        $at = new DateTimeImmutable('2026-10-24 09:00:00');

        foreach (['completed', 'failed', 'cancelled'] as $outcome) {
            $repository = $this->repository();
            $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);
            $execution = $repository->claim($execution->id, 'worker', 300);

            match ($outcome) {
                'completed' => $execution->markCompleted($at),
                'failed' => $execution->markFailed('boom', 'RuntimeException', $at),
                'cancelled' => $execution->markCancelled($at, 'operator request'),
            };

            $repository->release($execution);

            $this->assertSame(
                '2026-10-24 09:00:00',
                $repository->find($execution->id)->finishedAt?->format('Y-m-d H:i:s'),
                "A [{$outcome}] execution must record when it finished.",
            );
        }
    }

    public function test_a_scheduled_retry_persists_no_finish(): void
    {
        $execution = $this->makeRetryingExecution(
            dueAt: '2026-10-24 09:05:00',
            now: '2026-10-24 09:00:00',
        );

        $this->assertNull(
            $this->repository()->find($execution->id)->finishedAt,
            'A waiting retry is not finished; retention must not see it.',
        );
    }

    public function test_cancelling_unclaimed_work_persists_finished_at(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);

        // The compare-and-set cancellation path writes the row directly
        // rather than going through the handle, so it needs its own proof.
        $cancelled = $repository->requestCancellation(
            $execution->id,
            'operator request',
            new DateTimeImmutable('2026-10-24 09:00:00'),
        );

        $this->assertTrue($cancelled->isCancelled());
        $this->assertSame('2026-10-24 09:00:00', $cancelled->finishedAt?->format('Y-m-d H:i:s'));
    }

    public function test_reclaiming_a_failed_execution_clears_its_finish(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);
        $execution = $repository->claim($execution->id, 'worker', 300);
        $execution->markFailed('boom', 'RuntimeException', new DateTimeImmutable('2026-10-24 09:00:00'));
        $repository->release($execution);

        $reclaimed = $repository->claim($execution->id, 'worker-b', 300);

        $this->assertNull(
            $reclaimed->finishedAt,
            'The claim must clear finished_at, or retention could delete a running execution.',
        );
        $this->assertNull(WorkflowExecutionModel::query()->sole()->finished_at);
    }

    public function test_a_sub_second_duration_survives_a_round_trip(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);
        $execution = $repository->claim($execution->id, 'worker', 300);

        $execution->startedAt = new DateTimeImmutable('2026-10-25 12:00:00.000000');
        $execution->markCompleted(new DateTimeImmutable('2026-10-25 12:00:00.455000'));
        $repository->release($execution);

        // The timestamp columns hold whole seconds, so recomputing this
        // after a reload would report 0 ms. The value is captured at the
        // transition and persisted instead.
        $this->assertSame(455, $repository->find($execution->id)->durationMs());
        $this->assertSame(455, WorkflowExecutionModel::query()->sole()->duration_ms);
    }

    public function test_re_executing_clears_the_recorded_duration(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('SyncCustomerWorkflow', steps: ['LoadCustomer']);
        $execution = $repository->claim($execution->id, 'worker', 300);

        $execution->markFailed('boom', 'RuntimeException', new DateTimeImmutable('2026-10-25 12:00:01'));
        $repository->release($execution);

        $reclaimed = $repository->claim($execution->id, 'worker-b', 300);

        // It has not finished, so it has no duration to report.
        $this->assertNull($reclaimed->durationMs());
        $this->assertNull(WorkflowExecutionModel::query()->sole()->duration_ms);
    }

    /**
     * An execution whose step failed retryably and is waiting for
     * $dueAt, built through the real repository write path: created,
     * claimed, marked retrying, released.
     */
    protected function makeRetryingExecution(
        string $dueAt,
        string $now,
        string $workflow = 'SyncCustomerWorkflow',
    ): WorkflowExecution {
        Date::setTestNow($now);

        $repository = $this->repository();
        $execution = $repository->create($workflow, steps: ['LoadCustomer']);
        $execution = $repository->claim($execution->id, 'worker', 300);

        $execution->markRetrying('boom', 'RuntimeException', new DateTimeImmutable($dueAt));
        $repository->release($execution);

        return $execution;
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
