<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\FixedClock;
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
use Laraflow\Workflow\RecoveryService;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * Cancellation: immediate for work nobody is running, cooperative for
 * work a live worker owns, terminal everywhere afterwards.
 */
class CancellationTest extends TestCase
{
    // ------------------------------------------------------------------
    // Immediate cancellation of unclaimed work
    // ------------------------------------------------------------------

    public function test_a_pending_execution_is_cancelled_immediately(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);

        $cancelled = $runner->cancel($execution->id, 'no longer needed');

        $this->assertTrue($cancelled->isCancelled());
        $this->assertSame('no longer needed', $cancelled->cancelReason);
        $this->assertNotNull($cancelled->cancelledAt);
        $this->assertSame(0, StepOne::$runs, 'Nothing was allowed to run.');
    }

    public function test_a_failed_execution_is_cancelled_immediately(): void
    {
        StepTwo::$failTimes = 1;
        $runner = $this->app->make(WorkflowRunner::class);

        try {
            $runner->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $cancelled = $runner->cancel(WorkflowExecutionModel::query()->value('id'));

        $this->assertTrue($cancelled->isCancelled());
        $this->assertNull($cancelled->cancelReason, 'A reason is optional.');
    }

    public function test_cancelling_a_retrying_execution_voids_its_schedule(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 1;
        $runner = $this->app->make(WorkflowRunner::class);

        $execution = $runner->run(new PolicyWorkflow);
        $this->assertTrue($execution->isRetrying());

        $cancelled = $runner->cancel($execution->id, 'give up');

        $this->assertTrue($cancelled->isCancelled());
        $this->assertNull($cancelled->nextRetryAt);
        $this->assertNull(WorkflowExecutionModel::query()->sole()->next_retry_at);
    }

    public function test_a_cancelled_retry_is_invisible_to_the_recovery_scan(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 1;

        Date::setTestNow('2026-10-23 12:00:00');

        try {
            $runner = $this->app->make(WorkflowRunner::class);
            $execution = $runner->run(new PolicyWorkflow);
            $runner->cancel($execution->id);

            // Well past the voided due time: recovery must not resurrect it.
            Date::setTestNow('2026-10-23 12:30:00');

            $this->assertSame(
                [],
                $this->app->make(RecoveryService::class)->scan(300, null, 100, 3),
            );
        } finally {
            Date::setTestNow();
        }
    }

    // ------------------------------------------------------------------
    // Cooperative cancellation of a running execution
    // ------------------------------------------------------------------

    public function test_a_running_execution_stops_at_the_next_step_boundary(): void
    {
        Event::fake([WorkflowExecutionCancelled::class]);

        $runner = $this->app->make(WorkflowRunner::class);

        // Mid-step-one, "another process" asks for cancellation.
        StepOne::$beforeReturn = function () use ($runner) {
            $pending = $runner->cancel(WorkflowExecutionModel::query()->value('id'), 'operator');

            // A live worker owns it, so this is a request, not a change.
            $this->assertFalse($pending->isCancelled());
            $this->assertTrue($pending->isRunning());
            $this->assertNotNull($pending->cancelRequestedAt);
        };

        $execution = $runner->run(new TwoStepWorkflow);

        $this->assertTrue($execution->isCancelled());
        $this->assertSame('operator', $execution->cancelReason);

        // The step in flight finished and checkpointed; the next never ran.
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(0, StepTwo::$runs);

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertSame(StepStatus::Completed->value, $steps[0]->status);
        $this->assertSame(StepStatus::Pending->value, $steps[1]->status);

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Cancelled->value, $model->status);
        $this->assertNull($model->claim_token, 'Cancelling releases the claim.');
        $this->assertSame(
            'from-step-one',
            $model->context['one'] ?? null,
            'Work completed before the cancellation is kept.',
        );

        Event::assertDispatchedTimes(WorkflowExecutionCancelled::class, 1);
    }

    public function test_a_cancellation_requested_after_the_last_step_does_not_prevent_completion(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        // Requested during the final step: there is no later boundary,
        // so the execution legitimately completes.
        StepTwo::$beforeReturn = function () use ($runner) {
            $runner->cancel(WorkflowExecutionModel::query()->value('id'), 'too late');
        };

        $execution = $runner->run(new TwoStepWorkflow);

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    public function test_a_cancellation_request_outlives_the_worker_it_was_meant_for(): void
    {
        // Requested against a live worker that then dies before reaching
        // a step boundary. Nothing clears the request, so the recovering
        // worker is the one that honours it — a cancellation is not lost
        // just because the process holding the claim was.
        $repository = $this->app->make(ExecutionRepository::class);
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        $repository->claim($execution->id, 'doomed-worker', 300);
        $repository->requestCancellation($execution->id, 'stop it', new DateTimeImmutable);

        // That worker never comes back; its claim goes stale.
        $recovered = $this->staleTolerantRunner()->execute($execution->id, new TwoStepWorkflow);

        $this->assertTrue($recovered->isCancelled());
        $this->assertSame('stop it', $recovered->cancelReason);
        $this->assertSame(0, StepOne::$runs, 'The request is honoured before any step runs.');
    }

    // ------------------------------------------------------------------
    // State validation
    // ------------------------------------------------------------------

    public function test_a_completed_execution_cannot_be_cancelled(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->run(new TwoStepWorkflow);

        $this->expectException(ExecutionNotCancellableException::class);

        $runner->cancel($execution->id);
    }

    public function test_cancelling_twice_is_an_idempotent_no_op(): void
    {
        Event::fake([WorkflowExecutionCancelled::class]);

        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);

        $runner->cancel($execution->id, 'first');
        $again = $runner->cancel($execution->id, 'second');

        $this->assertTrue($again->isCancelled());
        $this->assertSame('first', $again->cancelReason, 'The original cancellation stands.');

        // The event describes a state change; there was only one.
        Event::assertDispatchedTimes(WorkflowExecutionCancelled::class, 1);
    }

    public function test_cancelling_an_unknown_execution_fails_clearly(): void
    {
        $this->expectException(ExecutionNotFoundException::class);

        $this->app->make(WorkflowRunner::class)->cancel(999999);
    }

    // ------------------------------------------------------------------
    // Cancelled is terminal
    // ------------------------------------------------------------------

    public function test_a_cancelled_execution_cannot_be_executed_or_dispatched(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);
        $runner->cancel($execution->id);

        try {
            $runner->execute($execution->id, new TwoStepWorkflow);
            $this->fail('Expected ExecutionNotRunnableException.');
        } catch (ExecutionNotRunnableException $exception) {
            $this->assertStringContainsString('cancelled', $exception->getMessage());
        }

        $this->expectException(ExecutionNotRunnableException::class);

        $this->app->make(WorkflowDispatcher::class)->dispatchExecution($execution->id);
    }

    // ------------------------------------------------------------------
    // Console and wiring
    // ------------------------------------------------------------------

    public function test_the_cancel_command_cancels_and_records_the_reason(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);

        $this->artisan('laraflow:cancel', [
            'execution' => (string) $execution->id,
            '--reason' => 'customer withdrew the order',
        ])->assertExitCode(0);

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Cancelled->value, $model->status);
        $this->assertSame('customer withdrew the order', $model->cancel_reason);
    }

    public function test_the_cancel_command_reports_a_terminal_execution_as_a_failure(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $this->artisan('laraflow:cancel', ['execution' => (string) $execution->id])
            ->assertExitCode(1);
    }

    public function test_cancellation_timestamps_come_from_the_injected_clock(): void
    {
        $clock = new FixedClock('2026-10-23 15:00:00');

        $runner = new WorkflowRunner(
            repository: new EloquentExecutionRepository($clock),
            container: $this->app,
            logger: new ArrayLogger,
            clock: $clock,
        );

        $execution = $runner->start(new TwoStepWorkflow);
        $cancelled = $runner->cancel($execution->id);

        $this->assertSame('2026-10-23 15:00:00', $cancelled->cancelledAt?->format('Y-m-d H:i:s'));
    }

    public function test_cancellation_cannot_race_a_worker_claiming_the_execution(): void
    {
        $repository = $this->app->make(ExecutionRepository::class);
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        // The worker claims first; cancellation becomes a request and
        // the claim survives intact.
        $repository->claim($execution->id, 'worker', 300);

        $requested = $repository->requestCancellation($execution->id, 'racing', new DateTimeImmutable);

        $this->assertTrue($requested->isRunning());
        $this->assertSame('worker', $requested->claimToken, 'The live claim must not be stolen.');
        $this->assertNotNull($requested->cancelRequestedAt);
    }

    /**
     * A runner that will reclaim any stale claim immediately, standing
     * in for the worker that picks up after a dead one.
     */
    protected function staleTolerantRunner(): WorkflowRunner
    {
        return new WorkflowRunner(
            repository: $this->app->make(ExecutionRepository::class),
            container: $this->app,
            logger: new ArrayLogger,
            options: new RunnerOptions(staleAfterSeconds: 0),
        );
    }
}
