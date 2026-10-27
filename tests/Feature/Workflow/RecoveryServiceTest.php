<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\RecoveryCandidate;
use Laraflow\Workflow\RecoveryService;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

class RecoveryServiceTest extends TestCase
{
    // ------------------------------------------------------------------
    // scan()
    // ------------------------------------------------------------------

    public function test_scan_finds_a_stale_running_execution(): void
    {
        $execution = $this->makeStaleExecution();

        $candidates = $this->service()->scan(
            staleAfterSeconds: 300,
            pendingAfterSeconds: null,
            limit: 100,
            abandonAfterAttempts: 3,
        );

        $this->assertCount(1, $candidates);
        $this->assertSame($execution->id, $candidates[0]->execution->id);
        $this->assertSame(WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM, $candidates[0]->reason);
        $this->assertSame(1, $candidates[0]->attempts);
        $this->assertFalse($candidates[0]->abandoned);
    }

    public function test_scan_leaves_fresh_claims_alone(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);
        $this->app->make(ExecutionRepository::class)->claim($execution->id, 'live-worker', 300);

        Date::setTestNow('2026-10-22 10:02:00');

        $this->assertSame([], $this->service()->scan(300, null, 100, 3));
    }

    public function test_scan_includes_pending_executions_only_when_asked(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        Date::setTestNow('2026-10-22 10:30:00');

        $this->assertSame([], $this->service()->scan(300, null, 100, 3));

        $candidates = $this->service()->scan(300, 300, 100, 3);

        $this->assertCount(1, $candidates);
        $this->assertSame($execution->id, $candidates[0]->execution->id);
        $this->assertSame(WorkflowExecutionRetryScheduled::REASON_NEVER_DISPATCHED, $candidates[0]->reason);
        $this->assertSame(0, $candidates[0]->attempts);
    }

    public function test_scan_flags_executions_at_the_attempt_bound_as_abandoned(): void
    {
        $execution = $this->makeStaleExecution();

        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->where('position', 0)
            ->update(['attempts' => 3]);

        $candidates = $this->service()->scan(300, null, 100, abandonAfterAttempts: 3);

        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]->abandoned);
        $this->assertSame(3, $candidates[0]->attempts);

        // One attempt below the bound is still recoverable.
        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->update(['attempts' => 2]);

        $candidates = $this->service()->scan(300, null, 100, abandonAfterAttempts: 3);

        $this->assertFalse($candidates[0]->abandoned);
    }

    public function test_the_scan_limit_is_shared_across_stale_and_pending_work(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $this->makeStaleExecution(freezeTime: false);
        $this->makeStaleExecution(freezeTime: false);
        $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        Date::setTestNow('2026-10-22 10:30:00');

        $candidates = $this->service()->scan(300, 300, limit: 2, abandonAfterAttempts: 3);

        $this->assertCount(2, $candidates);
        $this->assertSame(
            [WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM, WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM],
            array_map(fn (RecoveryCandidate $candidate) => $candidate->reason, $candidates),
        );
    }

    public function test_scan_flags_executions_whose_workflow_class_is_gone_as_unrecoverable(): void
    {
        $execution = $this->makeStaleExecution();

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'workflow' => 'App\\Workflows\\DeletedWorkflow',
        ]);

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->assertTrue($candidate->workflowMissing);
        $this->assertFalse($candidate->retryable());
    }

    public function test_dispatch_refuses_a_candidate_with_a_missing_workflow_class(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'workflow' => 'App\\Workflows\\DeletedWorkflow',
        ]);

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer exists');

        try {
            $this->service()->dispatch($candidate);
        } finally {
            // The infinite-loop guard: a job that would fail before any
            // step runs (so attempts never grow) must never be queued.
            Queue::assertNothingPushed();
        }
    }

    // ------------------------------------------------------------------
    // Due retries
    // ------------------------------------------------------------------

    public function test_scan_finds_a_due_retry_and_exempts_it_from_the_abandonment_bound(): void
    {
        $execution = $this->makeDueRetryExecution(dueInSeconds: 300);

        // Push the durable attempt count far past the interruption bound:
        // the policy budget, not recovery, owns a scheduled retry.
        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->update(['attempts' => 5]);

        Date::setTestNow('2026-10-22 10:06:00');

        [$candidate] = $this->service()->scan(300, null, 100, abandonAfterAttempts: 3);

        $this->assertSame($execution->id, $candidate->execution->id);
        $this->assertSame(WorkflowExecutionRetryScheduled::REASON_RETRY_DUE, $candidate->reason);
        $this->assertSame(5, $candidate->attempts);
        $this->assertFalse($candidate->abandoned, 'Abandoning a policy-live retry would strand every budget above the bound.');
        $this->assertTrue($candidate->retryable());
    }

    public function test_scan_leaves_retries_that_are_not_yet_due_alone(): void
    {
        $this->makeDueRetryExecution(dueInSeconds: 3600);

        Date::setTestNow('2026-10-22 10:30:00');

        $this->assertSame([], $this->service()->scan(300, null, 100, 3));
    }

    public function test_the_due_retry_scan_can_be_skipped(): void
    {
        $this->makeDueRetryExecution(dueInSeconds: 60);

        Date::setTestNow('2026-10-22 10:30:00');

        $this->assertSame(
            [],
            $this->service()->scan(300, null, 100, 3, includeDueRetries: false),
        );
    }

    public function test_a_stale_claim_whose_step_overran_its_deadline_is_reported_as_a_timeout(): void
    {
        $execution = $this->makeStaleExecution();

        // The interrupted running step had a deadline that passed long
        // before the scan (claimed 10:00, scanned 10:30).
        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->where('position', 0)
            ->update(['timeout_at' => '2026-10-22 10:00:30']);

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->assertSame(WorkflowExecutionRetryScheduled::REASON_STEP_TIMEOUT, $candidate->reason);
    }

    public function test_the_limit_is_shared_across_all_three_scans_in_urgency_order(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');
        $this->makeStaleExecution(freezeTime: false);
        $this->makeStaleExecution(freezeTime: false);

        $this->makeDueRetryExecution(dueInSeconds: 60);

        Date::setTestNow('2026-10-22 10:30:00');

        $candidates = $this->service()->scan(300, null, limit: 2, abandonAfterAttempts: 3);

        $this->assertSame(
            [
                WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM,
                WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM,
            ],
            array_map(fn (RecoveryCandidate $candidate) => $candidate->reason, $candidates),
            'Lost work outranks scheduled work under a shared limit.',
        );

        $candidates = $this->service()->scan(300, null, limit: 3, abandonAfterAttempts: 3);

        $this->assertSame(
            WorkflowExecutionRetryScheduled::REASON_RETRY_DUE,
            $candidates[2]->reason,
        );
    }

    public function test_dispatching_a_due_retry_queues_it_and_says_why(): void
    {
        Queue::fake();
        Event::fake([WorkflowExecutionRetryScheduled::class]);

        $execution = $this->makeDueRetryExecution(dueInSeconds: 60);

        Date::setTestNow('2026-10-22 10:30:00');

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->service()->dispatch($candidate);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );

        Event::assertDispatched(
            WorkflowExecutionRetryScheduled::class,
            fn (WorkflowExecutionRetryScheduled $event): bool => $event->executionId === $execution->id
                && $event->reason === WorkflowExecutionRetryScheduled::REASON_RETRY_DUE,
        );
    }

    public function test_run_inline_delivers_a_due_retry_to_completion(): void
    {
        // The queueless/sync-driver delivery path, end to end: the step
        // failed once, its retry came due, and recovery runs it here.
        $execution = $this->makeDueRetryExecution(dueInSeconds: 60);

        Date::setTestNow('2026-10-22 10:30:00');

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $recovered = $this->service()->runInline($candidate);

        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(2, StepTwo::$runs, 'Attempt 2 ran and succeeded.');
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->whereKey($execution->id)->value('status'),
        );
    }

    // ------------------------------------------------------------------
    // dispatch()
    // ------------------------------------------------------------------

    public function test_dispatch_queues_the_execution_and_fires_retry_scheduled(): void
    {
        Queue::fake();
        Event::fake([WorkflowExecutionRetryScheduled::class]);

        $execution = $this->makeStaleExecution();
        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->service()->dispatch($candidate);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );

        Event::assertDispatched(
            WorkflowExecutionRetryScheduled::class,
            function (WorkflowExecutionRetryScheduled $event) use ($execution): bool {
                return $event->executionId === $execution->id
                    && $event->reason === WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM
                    && $event->attempts === 1;
            }
        );
    }

    public function test_dispatch_refuses_an_abandoned_candidate(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();
        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->where('position', 0)
            ->update(['attempts' => 3]);

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('abandoned by recovery');

        try {
            $this->service()->dispatch($candidate);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    // ------------------------------------------------------------------
    // runInline()
    // ------------------------------------------------------------------

    public function test_run_inline_completes_the_execution_in_process(): void
    {
        Event::fake([WorkflowExecutionRetryScheduled::class]);

        $execution = $this->makeStaleExecution();
        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $recovered = $this->service()->runInline($candidate);

        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );

        // The fixture's worker died before step one's handle() ran, so
        // recovery runs both steps exactly once each.
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);

        Event::assertDispatched(WorkflowExecutionRetryScheduled::class);
    }

    public function test_run_inline_refuses_an_abandoned_candidate(): void
    {
        $execution = $this->makeStaleExecution();
        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->update(['attempts' => 3]);

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->expectException(InvalidArgumentException::class);

        $this->service()->runInline($candidate);
    }

    public function test_run_inline_refuses_a_missing_workflow_class(): void
    {
        $execution = $this->makeStaleExecution();

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'workflow' => 'App\\Workflows\\DeletedWorkflow',
        ]);

        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer exists');

        $this->service()->runInline($candidate);
    }

    public function test_run_inline_propagates_a_step_failure(): void
    {
        StepTwo::$failTimes = 1;

        $this->makeStaleExecution();
        [$candidate] = $this->service()->scan(300, null, 100, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('step two blew up');

        $this->service()->runInline($candidate);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * An execution whose worker died mid-step: claimed, step one marked
     * running (attempt 1), claim now older than stale_after. The clock
     * ends up 30 minutes after the claim.
     */
    protected function makeStaleExecution(bool $freezeTime = true): WorkflowExecution
    {
        if ($freezeTime) {
            Date::setTestNow('2026-10-22 10:00:00');
        }

        $repository = $this->app->make(ExecutionRepository::class);
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        $repository->claim($execution->id, bin2hex(random_bytes(16)), 300);

        $steps = $repository->steps($execution);
        $steps[0]->markRunning(Date::now()->toImmutable());
        $repository->saveStep($steps[0]);

        if ($freezeTime) {
            Date::setTestNow('2026-10-22 10:30:00');
        }

        return $execution;
    }

    /**
     * An execution whose second step failed retryably at 10:00:00 under a
     * policy with the given delay, built through the real failure path:
     * it is `retrying`, due at 10:00:00 + $dueInSeconds.
     */
    protected function makeDueRetryExecution(int $dueInSeconds): WorkflowExecution
    {
        Date::setTestNow('2026-10-22 10:00:00');

        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(
            maxAttempts: 3,
            baseDelaySeconds: $dueInSeconds,
            maxDelaySeconds: max(300, $dueInSeconds),
            jitter: 0.0,
        );
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);

        assert($execution->isRetrying());

        return $execution;
    }

    protected function service(): RecoveryService
    {
        return $this->app->make(RecoveryService::class);
    }
}
