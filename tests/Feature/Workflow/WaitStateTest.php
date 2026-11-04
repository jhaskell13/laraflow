<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionSignalled;
use Laraflow\Events\WorkflowStepWaiting;
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\InvalidSignalException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\WaitTimedOutException;
use Laraflow\Tests\Support\ApprovalStep;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\PausedWorkflow;
use Laraflow\Tests\Support\PauseStep;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\SignalInboxModel;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\Tenancy\TenantCeilings;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use RuntimeException;

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
        // assertEquals: MySQL's JSON format reorders object keys.
        $this->assertEquals(['one' => 'from-step-one', 'paused' => true], $row->context);

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

    public function test_a_signal_for_another_name_is_kept_and_never_answers_this_wait(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        $execution = $this->runner()->run(new ApprovalWorkflow);

        // Waiting, but not on that name: kept in the inbox for a park
        // that may ask for it, and nothing about the real wait moves.
        $kept = $this->runner()->signal($execution->id, 'someone-elses-signal', ['n' => 1]);

        $this->assertTrue($kept->isWaiting());
        $this->assertNull(WorkflowExecutionModel::query()->sole()->next_retry_at);
        $this->assertNull(StepExecutionModel::query()->where('position', 1)->value('output'));
        $this->assertSame(['someone-elses-signal'], SignalInboxModel::query()->pluck('name')->all());

        $this->runner()->signal($execution->id, 'manager-approval');
        $this->runner()->execute($execution->id, new ApprovalWorkflow);

        // Finished: nothing can park again, so a signal is refused.
        $this->expectException(SignalRefusedException::class);
        $this->runner()->signal($execution->id, 'manager-approval');
    }

    public function test_a_signal_sent_before_the_step_parks_answers_the_park_in_the_same_claim(): void
    {
        // The Milestone 10 audit: a signal that arrived before its step
        // parked was refused, and the approval was lost to a race the
        // sender could not see.
        Event::fake([WorkflowStepWaiting::class]);

        $execution = $this->runner()->start(new ApprovalWorkflow);

        $kept = $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'dana']);
        $this->assertTrue($kept->isPending(), 'Kept, not delivered: nothing is due.');

        $finished = $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->assertTrue($finished->isCompleted(), 'The park found its signal and resumed at once.');
        $this->assertSame(1, ApprovalStep::$runs);
        $this->assertSame(1, StepTwo::$runs);
        $this->assertSame(['approved_by' => 'dana'], StepExecutionModel::query()->where('position', 1)->sole()->output);
        $this->assertSame(['approved_by' => 'dana'], data_get(WorkflowExecutionModel::query()->sole()->context, 'manager-approval'));
        $this->assertSame(0, SignalInboxModel::query()->count(), 'Consumed.');
        Event::assertNotDispatched(WorkflowStepWaiting::class);
    }

    public function test_a_signal_sent_while_an_earlier_step_runs_is_kept_and_the_latest_wins(): void
    {
        $execution = $this->runner()->start(new ApprovalWorkflow);

        StepOne::$beforeReturn = function () use ($execution): void {
            $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'dana']);
            $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'alex']);
        };

        $finished = $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->assertTrue($finished->isCompleted());
        $this->assertSame(['approved_by' => 'alex'], StepExecutionModel::query()->where('position', 1)->sole()->output);
        $this->assertSame(0, SignalInboxModel::query()->count());
    }

    public function test_the_inbox_matches_a_name_byte_for_byte(): void
    {
        $execution = $this->runner()->start(new ApprovalWorkflow);

        $this->runner()->signal($execution->id, 'Manager-Approval', ['approved_by' => 'mallory']);

        $parked = $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->assertTrue($parked->isWaiting(), 'Another name, on an engine that compares case-insensitively too.');
        $this->assertSame(1, SignalInboxModel::query()->count());

        // And the two names are two rows, not one overwriting the other.
        $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'dana']);
        $this->runner()->signal($execution->id, 'Manager-Approval', ['approved_by' => 'mallory']);
        $this->assertSame(1, SignalInboxModel::query()->count(), 'The real name went onto the waiting step, not the inbox.');
    }

    public function test_a_signal_landing_while_a_claim_holds_a_parked_step_is_kept_and_consumed_on_the_re_park(): void
    {
        $execution = $this->runner()->run(new ApprovalWorkflow);

        // An early claim (an operator's --now, a heartbeat) holds the
        // execution: it is running, the step still waiting. A signal
        // arrives in that window, just before the claim re-parks.
        $repository = new class($this->app->make(ClockInterface::class), $this->app->make(TenantCeilings::class)) extends EloquentExecutionRepository
        {
            public ?int $signalOn = null;

            public function park(WorkflowExecution $execution, \Laraflow\Workflow\StepExecution $step, ?DateTimeImmutable $nextEventAt): ?array
            {
                if ($this->signalOn !== null) {
                    $this->recordSignal($this->signalOn, 'manager-approval', ['approved_by' => 'dana'], $this->clock->now());
                    $this->signalOn = null;
                }

                return parent::park($execution, $step, $nextEventAt);
            }
        };
        $repository->signalOn = $execution->id;

        $finished = $this->makeRunner(
            repository: $repository,
            logger: new \Laraflow\Tests\Support\ArrayLogger,
            options: new \Laraflow\Workflow\RunnerOptions(staleAfterSeconds: 300),
            events: $this->app->make(\Laraflow\Events\EventEmitter::class),
            policies: new \Laraflow\Workflow\Policy\PolicyResolver,
        )->execute($execution->id, new ApprovalWorkflow, ignoreRetrySchedule: true);

        $this->assertTrue($finished->isCompleted(), 'Refused before the inbox; now it answers the wait.');
        $this->assertSame(1, ApprovalStep::$runs);
        $this->assertSame(0, SignalInboxModel::query()->count());
    }

    public function test_a_signal_for_an_execution_that_cannot_park_again_is_refused(): void
    {
        $repository = $this->app->make(\Laraflow\Workflow\ExecutionRepository::class);

        $cancelled = $this->runner()->start(new ApprovalWorkflow);
        $this->runner()->cancel($cancelled->id);

        StepTwo::$failTimes = 1;
        $failed = $this->runner()->start(new ApprovalWorkflow);
        $this->runner()->signal($failed->id, 'manager-approval');

        try {
            $this->runner()->execute($failed->id, new ApprovalWorkflow);
        } catch (RuntimeException) {
            // Step two failed it terminally, after the approval resumed.
        }

        foreach ([$cancelled->id, $failed->id] as $id) {
            try {
                $this->runner()->signal($id, 'manager-approval');
                $this->fail("Execution {$id} cannot park again; its signal must be refused.");
            } catch (SignalRefusedException) {
                // Expected.
            }
        }

        // The machinery's names are never buffered, for any execution.
        $pending = $this->runner()->start(new ApprovalWorkflow);

        $this->expectException(SignalRefusedException::class);

        try {
            $repository->recordSignal($pending->id, 'laraflow.child.42', [], new DateTimeImmutable);
        } finally {
            $this->assertSame(0, SignalInboxModel::query()->count());
        }
    }

    public function test_a_buffered_signal_goes_with_its_execution(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        $repository = $this->app->make(\Laraflow\Workflow\ExecutionRepository::class);

        $scrubbed = $this->runner()->start(new ApprovalWorkflow);
        $deleted = $this->runner()->start(new ApprovalWorkflow);

        $this->runner()->signal($scrubbed->id, 'manager-approval', ['secret' => 'x']);
        $this->runner()->signal($deleted->id, 'manager-approval', ['secret' => 'y']);
        $this->runner()->cancel($scrubbed->id);
        $this->runner()->cancel($deleted->id);

        $later = new DateTimeImmutable('2026-12-01 00:00:00');

        // The scrub tier keeps the record and takes payloads — a kept
        // signal no park will ever consume among them.
        $repository->scrubPayloads([$scrubbed->id], [ExecutionStatus::Cancelled], $later, $later);
        $this->assertSame([$deleted->id], SignalInboxModel::query()->pluck('workflow_execution_id')->all());

        $repository->deleteExecutions([$deleted->id], [ExecutionStatus::Cancelled], $later);
        $this->assertSame(0, SignalInboxModel::query()->count());
    }

    public function test_reserved_and_empty_signal_names_are_refused_before_touching_storage(): void
    {
        $execution = $this->runner()->run(new ApprovalWorkflow);

        $refused = [];

        foreach (['', '  ', 'laraflow.child.9', 'Laraflow.child.9', str_repeat('x', 256)] as $name) {
            try {
                $this->runner()->signal($execution->id, $name);
            } catch (InvalidSignalException) {
                $refused[] = $name;
            }
        }

        $this->assertSame(['', '  ', 'laraflow.child.9', 'Laraflow.child.9', str_repeat('x', 256)], $refused);
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

    public function test_a_park_is_one_write_and_a_worker_dying_in_it_leaves_nothing_half_parked(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');

        // The step's park and the execution's used to be two commits, a
        // crash between them leaving the step waiting on an execution
        // still running — the window that refused a signal. Now the
        // worker dies before the one write, or after it; never between.
        try {
            $this->crashingRunner('park', call: 1, phase: 'before')->run(new PausedWorkflow);
            $this->fail('Expected the simulated crash.');
        } catch (\Laraflow\Tests\Support\SimulatedWorkerCrash) {
            // Worker died.
        }

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Running->value, $row->status);
        $this->assertSame(StepStatus::Running->value, StepExecutionModel::query()->where('position', 1)->value('status'), 'Not waiting on a running execution.');

        // A recovering worker reclaims the stale execution, re-runs the
        // interrupted step (the at-least-once boundary), and parks.
        Date::setTestNow('2026-10-28 10:01:00');
        $healed = $this->runner(staleAfter: 0)->execute($row->id, new PausedWorkflow);

        $this->assertTrue($healed->isWaiting());
        $this->assertSame(StepStatus::Waiting->value, StepExecutionModel::query()->where('position', 1)->value('status'));
    }

    public function test_a_duplicate_signal_before_the_resume_overwrites_the_payload(): void
    {
        $execution = $this->runner()->run(new ApprovalWorkflow);

        $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'dana']);

        // An at-least-once source redelivers; the last delivery wins —
        // and, crucially, an identical redelivery is not refused (on
        // MySQL an unchanged UPDATE counts zero rows, which must not
        // read as "not waiting").
        $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'dana']);
        $this->runner()->signal($execution->id, 'manager-approval', ['approved_by' => 'alex']);

        $resumed = $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $this->assertTrue($resumed->isCompleted());
        $this->assertSame(
            ['approved_by' => 'alex'],
            StepExecutionModel::query()->where('position', 1)->sole()->output,
        );
    }

    public function test_a_cancellation_requested_mid_step_is_honoured_at_the_park_boundary(): void
    {
        // cancel() lands while handle() runs; the step then asks to
        // park. The park is a step boundary, and an indefinite wait
        // must not swallow the request.
        $runner = $this->runner();
        PauseStep::$beforeReturn = function () use ($runner): void {
            $runner->cancel(WorkflowExecutionModel::query()->sole()->id, 'changed my mind');
        };

        $result = $runner->run(new PausedWorkflow);

        $this->assertTrue($result->isCancelled(), 'The boundary honoured the request instead of parking.');
        $this->assertSame(
            ExecutionStatus::Cancelled->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
        $this->assertSame('changed my mind', WorkflowExecutionModel::query()->sole()->cancel_reason);
    }

    public function test_a_cancellation_requested_as_the_park_releases_is_honoured_after_it(): void
    {
        // The request lands after the boundary check and before the
        // park's release, while the row still says running — so it is
        // recorded as a request, not an outright cancel. The runner's
        // post-park check is the only thing that can honour it; without
        // it the execution would sit on a wait with a request nobody
        // reads. Phase A moved that check out of the park; this is the
        // test the move was missing.
        $repository = $this->repositoryWithLateCancellationRequest();

        $this->makeRunner(repository: $repository)->run(new PausedWorkflow);

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Cancelled->value, $row->status, 'The post-park check did not honour the request.');
        $this->assertSame('late request', $row->cancel_reason);
        $this->assertSame(0, StepTwo::$runs, 'Nothing ran after the cancelled park.');
    }

    public function test_a_cancellation_requested_as_a_re_park_releases_is_honoured_after_it(): void
    {
        // The same window on the heartbeat path: an early claim on an
        // unsignalled wait parks the execution again, and the request
        // lands as that re-park releases.
        Date::setTestNow('2026-10-28 10:00:00');
        $execution = $this->runner()->run(new ApprovalWorkflow);

        $repository = $this->repositoryWithLateCancellationRequest();

        $this->makeRunner(repository: $repository)->execute($execution->id, new ApprovalWorkflow, ignoreRetrySchedule: true);

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Cancelled->value, $row->status, 'The post-re-park check did not honour the request.');
        $this->assertSame('late request', $row->cancel_reason);
    }

    public function test_heartbeat_style_reparks_do_not_count_toward_abandonment(): void
    {
        // A park, then two early claims that correctly park again: each
        // claim concludes in an orderly release, so none of them is
        // "fruitless" — only a claim that dies before any orderly write
        // may count toward the abandonment bound.
        Date::setTestNow('2026-10-28 10:00:00');
        $execution = $this->runner()->run(new ApprovalWorkflow);

        foreach ([1, 2] as $i) {
            $reparked = $this->runner()->execute($execution->id, new ApprovalWorkflow, ignoreRetrySchedule: true);
            $this->assertTrue($reparked->isWaiting());
        }

        $this->assertSame(
            0,
            WorkflowExecutionModel::query()->sole()->fruitless_claims,
            'An orderly re-park is progress, not a fruitless claim.',
        );
    }

    public function test_a_due_timed_wait_is_found_and_resumed_by_recovery(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        $execution = $this->runner()->run(new PausedWorkflow);

        Date::setTestNow('2026-10-28 10:05:00');
        $service = $this->app->make(\Laraflow\Workflow\RecoveryService::class);
        $this->assertCount(0, $service->scan(300, null, 100, 3), 'Not due yet: the scan leaves it alone.');

        Date::setTestNow('2026-10-28 10:10:00');
        $candidates = $service->scan(300, null, 100, 3);

        $this->assertCount(1, $candidates);
        $this->assertSame(
            \Laraflow\Workflow\RecoveryReason::WaitDue,
            $candidates[0]->reason,
        );
        $this->assertFalse($candidates[0]->abandoned, 'Due waits are exempt from the abandonment bound.');

        $resumed = $service->runInline($candidates[0]);

        $this->assertTrue($resumed->isCompleted());
        $this->assertSame(1, StepTwo::$runs, 'Recovery delivered the resume and the rest of the plan ran.');
    }

    public function test_a_delivery_for_a_parked_execution_is_a_quiet_no_op_for_the_queue_job(): void
    {
        $execution = $this->runner()->run(new ApprovalWorkflow);

        // A delayed delivery outliving its wait, or a heartbeat racing a
        // re-park: the job must absorb it like a not-due retry, not fail.
        $logger = new \Laraflow\Tests\Support\ArrayLogger;
        $this->app->instance(\Psr\Log\LoggerInterface::class, $logger);

        $job = new \Laraflow\Queue\ExecuteWorkflowJob($execution->id);
        $this->app->call([$job, 'handle']);

        $this->assertTrue(
            $logger->hasRecordContaining('info', 'parked on a wait'),
            'The refused claim is information, not a failed job.',
        );
        $this->assertTrue($this->app->make(\Laraflow\Workflow\ExecutionRepository::class)->find($execution->id)->isWaiting());
    }

    /**
     * A repository under which a cancellation request lands in the
     * instant between a park's last write and its release: the handle
     * already says waiting, the row still says running, so the request
     * is recorded as a request. Fires once, on the first such release.
     */
    protected function repositoryWithLateCancellationRequest(): EloquentExecutionRepository
    {
        return new class($this->app->make(ClockInterface::class), $this->app->make(TenantCeilings::class)) extends EloquentExecutionRepository
        {
            private bool $armed = true;

            public function release(WorkflowExecution $execution): void
            {
                if ($this->armed && $execution->isWaiting()) {
                    $this->armed = false;
                    $this->requestCancellation($execution->id, 'late request', $this->clock->now());
                }

                parent::release($execution);
            }
        };
    }

    protected function runner(int $staleAfter = 300): WorkflowRunner
    {
        return $this->makeRunner(
            repository: $this->app->make(\Laraflow\Workflow\ExecutionRepository::class),
            logger: new \Laraflow\Tests\Support\ArrayLogger,
            options: new \Laraflow\Workflow\RunnerOptions(staleAfterSeconds: $staleAfter),
            events: $this->app->make(\Laraflow\Events\EventEmitter::class),
            policies: new \Laraflow\Workflow\Policy\PolicyResolver,
        );
    }

    protected function crashingRunner(string $dieOn, int $call = 1, string $phase = 'before'): WorkflowRunner
    {
        return $this->makeRunner(
            repository: new \Laraflow\Tests\Support\CrashingExecutionRepository(
                $this->app->make(\Laraflow\Workflow\ExecutionRepository::class),
                $dieOn,
                $call,
                $phase,
            ),
            logger: new \Laraflow\Tests\Support\ArrayLogger,
            options: new \Laraflow\Workflow\RunnerOptions(staleAfterSeconds: 300),
        );
    }
}
