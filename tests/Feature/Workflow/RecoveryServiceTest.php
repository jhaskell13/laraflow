<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
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
            maxAttempts: 3,
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

        $candidates = $this->service()->scan(300, null, 100, maxAttempts: 3);

        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]->abandoned);
        $this->assertSame(3, $candidates[0]->attempts);

        // One attempt below the bound is still recoverable.
        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->update(['attempts' => 2]);

        $candidates = $this->service()->scan(300, null, 100, maxAttempts: 3);

        $this->assertFalse($candidates[0]->abandoned);
    }

    public function test_the_scan_limit_is_shared_across_stale_and_pending_work(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $this->makeStaleExecution(freezeTime: false);
        $this->makeStaleExecution(freezeTime: false);
        $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        Date::setTestNow('2026-10-22 10:30:00');

        $candidates = $this->service()->scan(300, 300, limit: 2, maxAttempts: 3);

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

    protected function service(): RecoveryService
    {
        return $this->app->make(RecoveryService::class);
    }
}
