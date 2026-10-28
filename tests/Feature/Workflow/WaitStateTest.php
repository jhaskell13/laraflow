<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Laraflow\Events\WorkflowExecutionSignalled;
use Laraflow\Events\WorkflowStepWaiting;
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\WaitTimedOutException;
use Laraflow\Tests\Support\ApprovalStep;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\PausedWorkflow;
use Laraflow\Tests\Support\PauseStep;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;

/**
 * The wait contract (docs/advanced-workflows.md): a wait is the step's
 * outcome boundary — everything before it checkpoints, the claim is
 * released, and resuming completes the step without re-running it.
 */
class WaitStateTest extends TestCase
{
    public function test_a_timed_wait_parks_the_execution_durably(): void
    {
        Event::fake([WorkflowStepWaiting::class]);
        Date::setTestNow('2026-10-28 10:00:00');

        $execution = $this->runner()->run(new PausedWorkflow);

        $this->assertTrue($execution->isWaiting());
        $this->assertSame(0, StepTwo::$runs, 'Steps after the park must not run.');

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Waiting->value, $row->status);
        $this->assertNull($row->claim_token, 'A parked execution owns no worker.');
        $this->assertSame('2026-10-28 10:10:00', $row->next_retry_at?->format('Y-m-d H:i:s'));

        $step = StepExecutionModel::query()->where('position', 1)->firstOrFail();
        $this->assertSame(StepStatus::Waiting->value, $step->status);
        $this->assertNull($step->wait_signal);
        $this->assertSame('2026-10-28 10:10:00', $step->next_retry_at?->format('Y-m-d H:i:s'));

        // Everything the step did before waiting is durable.
        $this->assertSame(['one' => 'from-step-one', 'paused' => true], $row->context);

        Event::assertDispatched(
            WorkflowStepWaiting::class,
            fn (WorkflowStepWaiting $event): bool => $event->position === 1
                && $event->signal === null
                && $event->resumeAt?->format('H:i:s') === '10:10:00',
        );
    }

    public function test_a_timed_wait_cannot_be_claimed_early_and_resumes_when_due(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        $execution = $this->runner()->run(new PausedWorkflow);

        Date::setTestNow('2026-10-28 10:05:00');

        try {
            $this->runner()->execute($execution->id, new PausedWorkflow);
            $this->fail('Expected the early claim to be refused.');
        } catch (ExecutionStillWaitingException $exception) {
            $this->assertStringContainsString('until', $exception->getMessage());
        }

        Date::setTestNow('2026-10-28 10:10:00');

        $resumed = $this->runner()->execute($execution->id, new PausedWorkflow);

        $this->assertTrue($resumed->isCompleted());
        $this->assertSame(1, PauseStep::$runs, 'Resuming completes the step; handle() never re-runs.');
        $this->assertSame(1, StepTwo::$runs);

        $step = StepExecutionModel::query()->where('position', 1)->firstOrFail();
        $this->assertSame(StepStatus::Completed->value, $step->status);
        $this->assertNull($step->output);

        // One handle() run is one attempt row, spanning run and park.
        $attempt = StepAttemptModel::query()->where('step_execution_id', $step->id)->sole();
        $this->assertSame(StepAttemptStatus::Completed->value, $attempt->status);
        $this->assertSame(600_000, $attempt->duration_ms);
    }

    public function test_a_signal_resumes_an_indefinite_wait_with_its_payload(): void
    {
        Event::fake([WorkflowExecutionSignalled::class]);
        Date::setTestNow('2026-10-28 10:00:00');

        $execution = $this->runner()->run(new ApprovalWorkflow);

        $this->assertTrue($execution->isWaiting());
        $this->assertNull(
            WorkflowExecutionModel::query()->sole()->next_retry_at,
            'An unbounded signal wait has no next instant; only the signal wakes it.',
        );

        // Not claimable by the clock, ever.
        try {
            $this->runner()->execute($execution->id, new ApprovalWorkflow);
            $this->fail('Expected the claim to be refused.');
        } catch (ExecutionStillWaitingException $exception) {
            $this->assertStringContainsString('indefinitely', $exception->getMessage());
        }

        $signalled = $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'dana']);

        $this->assertTrue($signalled->isWaiting(), 'Recording is not delivery.');
        Event::assertDispatched(
            WorkflowExecutionSignalled::class,
            fn (WorkflowExecutionSignalled $event): bool => $event->signal === 'manager-approval',
        );

        $resumed = $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->assertTrue($resumed->isCompleted());
        $this->assertSame(1, ApprovalStep::$runs);

        $step = StepExecutionModel::query()->where('position', 1)->firstOrFail();
        $this->assertSame(['approved_by' => 'dana'], $step->output, 'The payload is the step output.');
        $this->assertSame(
            ['approved_by' => 'dana'],
            data_get(WorkflowExecutionModel::query()->sole()->context, 'manager-approval'),
            'The payload lands in the context under the signal name.',
        );
    }

    public function test_a_signal_for_the_wrong_name_or_state_is_refused(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        $execution = $this->runner()->run(new ApprovalWorkflow);

        try {
            $this->runner()->signal($execution->id, 'someone-elses-signal');
            $this->fail('Expected the signal to be refused.');
        } catch (SignalRefusedException) {
            // Expected: waiting, but not on that name.
        }

        // Nothing durable moved: the real wait is untouched.
        $this->assertNull(WorkflowExecutionModel::query()->sole()->next_retry_at);
        $this->assertNull(StepExecutionModel::query()->where('position', 1)->value('output'));

        $this->runner()->signal($execution->id, 'manager-approval');
        $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->expectException(SignalRefusedException::class);
        $this->runner()->signal($execution->id, 'manager-approval');
    }

    public function test_reserved_and_empty_signal_names_are_refused_before_touching_storage(): void
    {
        $execution = $this->runner()->run(new ApprovalWorkflow);

        $refused = [];

        foreach (['', '  ', 'laraflow.child.9'] as $name) {
            try {
                $this->runner()->signal($execution->id, $name);
            } catch (InvalidArgumentException) {
                $refused[] = $name;
            }
        }

        $this->assertSame(['', '  ', 'laraflow.child.9'], $refused);
        $this->assertTrue($this->app->make(\Laraflow\Workflow\ExecutionRepository::class)->find($execution->id)->isWaiting());
    }

    public function test_a_signal_wait_past_its_deadline_fails_the_step(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        ApprovalStep::$timeoutSeconds = 3600;

        $execution = $this->runner()->run(new ApprovalWorkflow);
        $this->assertSame(
            '2026-10-28 11:00:00',
            WorkflowExecutionModel::query()->sole()->next_retry_at?->format('Y-m-d H:i:s'),
            'A bounded signal wait schedules its own deadline.',
        );

        Date::setTestNow('2026-10-28 11:00:01');

        try {
            $this->runner()->execute($execution->id, new ApprovalWorkflow);
            $this->fail('Expected the wait timeout.');
        } catch (WaitTimedOutException $exception) {
            $this->assertStringContainsString('manager-approval', $exception->getMessage());
        }

        $step = StepExecutionModel::query()->where('position', 1)->firstOrFail();
        $this->assertSame(StepStatus::Failed->value, $step->status);
        $this->assertSame(
            StepAttemptStatus::TimedOut->value,
            StepAttemptModel::query()->where('step_execution_id', $step->id)->sole()->status,
        );
    }

    public function test_a_timed_out_wait_retries_under_the_steps_policy_and_reposts_the_request(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        ApprovalStep::$timeoutSeconds = 3600;
        ApprovalWorkflow::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 30, jitter: 0.0);

        $execution = $this->runner()->run(new ApprovalWorkflow);

        Date::setTestNow('2026-10-28 11:00:01');
        $retrying = $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->assertTrue($retrying->isRetrying(), 'The policy granted the timeout another attempt.');

        Date::setTestNow('2026-10-28 11:00:31');
        $reposted = $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->assertTrue($reposted->isWaiting());
        $this->assertSame(2, ApprovalStep::$runs, 'Retrying a wait re-runs the step: the request is re-posted.');
        $this->assertSame(2, data_get(WorkflowExecutionModel::query()->sole()->context, 'approval_requested'));
    }

    public function test_cancelling_a_waiting_execution_is_immediate(): void
    {
        $execution = $this->runner()->run(new ApprovalWorkflow);

        $cancelled = $this->runner()->cancel($execution->id, 'no longer needed');

        $this->assertTrue($cancelled->isCancelled());
        $this->assertNotNull($cancelled->finishedAt);

        $this->expectException(SignalRefusedException::class);
        $this->runner()->signal($execution->id, 'manager-approval');
    }

    public function test_a_crash_between_park_and_release_heals_by_parking_again(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');

        // The park checkpoints (step waiting + context), then the worker
        // dies before releasing the execution.
        try {
            $this->crashingRunner('release', call: 1, phase: 'before')->run(new PausedWorkflow);
            $this->fail('Expected the simulated crash.');
        } catch (\Laraflow\Tests\Support\SimulatedWorkerCrash) {
            // Worker died.
        }

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Running->value, $row->status, 'The release never happened.');

        // A recovering worker reclaims the stale execution and, finding
        // the wait not due, parks it again rather than resuming early.
        Date::setTestNow('2026-10-28 10:01:00');
        $healed = $this->runner(staleAfter: 0)->execute($row->id, new PausedWorkflow);

        $this->assertTrue($healed->isWaiting());
        $this->assertSame(1, PauseStep::$runs, 'Healing must not re-run the parked step.');
        $this->assertSame(
            '2026-10-28 10:10:00',
            WorkflowExecutionModel::query()->sole()->next_retry_at?->format('Y-m-d H:i:s'),
            'The original resume instant stands.',
        );
    }

    protected function runner(int $staleAfter = 300): WorkflowRunner
    {
        return new WorkflowRunner(
            repository: $this->app->make(\Laraflow\Workflow\ExecutionRepository::class),
            container: $this->app,
            logger: new \Laraflow\Tests\Support\ArrayLogger,
            options: new \Laraflow\Workflow\RunnerOptions(staleAfterSeconds: $staleAfter),
            events: $this->app->make(\Laraflow\Events\EventEmitter::class),
            policies: new \Laraflow\Workflow\Policy\PolicyResolver,
        );
    }

    protected function crashingRunner(string $dieOn, int $call = 1, string $phase = 'before'): WorkflowRunner
    {
        return new WorkflowRunner(
            repository: new \Laraflow\Tests\Support\CrashingExecutionRepository(
                $this->app->make(\Laraflow\Workflow\ExecutionRepository::class),
                $dieOn,
                $call,
                $phase,
            ),
            container: $this->app,
            logger: new \Laraflow\Tests\Support\ArrayLogger,
            options: new \Laraflow\Workflow\RunnerOptions(staleAfterSeconds: 300),
        );
    }
}
