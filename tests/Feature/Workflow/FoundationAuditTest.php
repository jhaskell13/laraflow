<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Exceptions\Workflow\PayloadTooLargeException;
use Laraflow\Exceptions\Workflow\StepPreviouslyFailedException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\MutableWorkflow;
use Laraflow\Tests\Support\PausedWorkflow;
use Laraflow\Tests\Support\PauseStep;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\RecoveryService;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * Regressions for the defects the Milestone 7 closing audit found in
 * the foundations — each test names the contract it pins.
 */
class FoundationAuditTest extends TestCase
{
    public function test_a_step_may_return_a_scalar_output(): void
    {
        // Step::handle(): mixed and DurableValue both allow scalars; the
        // checkpoint rejected them with a TypeError and left the
        // execution claimed.
        foreach (['ok', 42, 1.5, false] as $output) {
            StepOne::$output = $output;

            $execution = $this->runner()->run(new TwoStepWorkflow);

            $this->assertTrue($execution->isCompleted());
            $this->assertSame($output, $this->app->make(ExecutionRepository::class)->steps($execution)[0]->output);
        }
    }

    public function test_under_continue_a_prior_terminal_failure_is_not_re_run_on_a_resume(): void
    {
        // Step two fails terminally (no retries), the pause parks the
        // execution, and the resume must not run step two again: its
        // budget was spent in the earlier claim. The execution still
        // ends failed, naming the earlier failure.
        Date::setTestNow('2026-10-28 10:00:00');
        PolicyWorkflow::$steps = [StepTwo::class, PauseStep::class, StepOne::class];
        PolicyWorkflow::$failurePolicy = FailurePolicy::Continue;
        StepTwo::$failTimes = 1;

        $parked = $this->runner()->run(new PolicyWorkflow);
        $this->assertTrue($parked->isWaiting());
        $this->assertSame(1, StepTwo::$runs);

        Date::setTestNow('2026-10-28 10:10:00');

        try {
            $this->runner()->execute($parked->id, new PolicyWorkflow);
            $this->fail('Expected the execution to fail with the earlier step failure.');
        } catch (StepPreviouslyFailedException $exception) {
            $this->assertStringContainsString('step two blew up', $exception->getMessage());
        }

        $this->assertSame(1, StepTwo::$runs, 'A spent budget is not re-spent on a resume.');
        $this->assertSame(1, StepOne::$runs, 'The steps after the park still ran.');
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);

        // An operator re-executing the failed execution is the one case
        // that re-runs it — that is what re-execution means.
        $recovered = $this->runner()->execute($parked->id, new PolicyWorkflow);
        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(2, StepTwo::$runs);
    }

    public function test_a_failed_steps_context_writes_do_not_reach_the_next_step(): void
    {
        // Step one writes to the context and then throws; under Continue
        // step two must see neither the key in memory nor on disk.
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$failurePolicy = FailurePolicy::Continue;
        StepOne::$beforeReturn = fn () => throw new RuntimeException('after writing');

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
            // The execution fails at the end, as Continue promises.
        }

        $this->assertNull(StepTwo::$seenContextValue, 'The failed step contributed nothing in memory.');
        $this->assertArrayNotHasKey('one', WorkflowExecutionModel::query()->sole()->context ?? []);
    }

    public function test_a_checkpoint_failure_records_the_real_error_from_a_legal_state(): void
    {
        // The handle said "completed" a moment before the checkpoint;
        // a transient failure there used to drive the failure path into
        // InvalidStateTransitionException and leave the execution
        // claimed. The real error must be what the step row records.
        $repository = new class(null) extends EloquentExecutionRepository
        {
            public int $failures = 1;

            public function checkpoint(WorkflowExecution $execution, StepExecution $step, ?StepAttempt $attempt = null): void
            {
                if ($this->failures-- > 0) {
                    throw new RuntimeException('deadlock detected');
                }

                parent::checkpoint($execution, $step, $attempt);
            }
        };

        $runner = new WorkflowRunner(
            repository: $repository,
            container: $this->app,
            logger: new ArrayLogger,
            options: new RunnerOptions(staleAfterSeconds: 300),
        );

        try {
            $runner->run(new TwoStepWorkflow);
            $this->fail('Expected the checkpoint failure to surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('deadlock detected', $exception->getMessage());
        }

        $row = StepExecutionModel::query()->where('position', 0)->sole();
        $this->assertSame(StepStatus::Failed->value, $row->status);
        $this->assertSame('deadlock detected', $row->error);
        $this->assertSame(RuntimeException::class, $row->error_class);

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $execution->status);
        $this->assertNull($execution->claim_token, 'The claim is released, not left to go stale.');
    }

    public function test_a_waiting_step_whose_class_disappeared_fails_as_a_definition_fault(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        MutableWorkflow::$steps = [PauseStep::class];
        $parked = $this->runner()->run(new MutableWorkflow);
        $this->assertTrue($parked->isWaiting());

        // A deploy removed the step class; the plan still names it.
        StepExecutionModel::query()->where('position', 0)->update(['step' => 'App\\Steps\\Gone']);
        // Deliberately invalid: the class is gone, which is the point.
        /** @phpstan-ignore assign.propertyType */
        MutableWorkflow::$steps = ['App\\Steps\\Gone'];

        Date::setTestNow('2026-10-28 10:10:00');

        try {
            $this->runner()->execute($parked->id, new MutableWorkflow);
            $this->fail('Expected the definition fault.');
        } catch (WorkflowDefinitionMismatchException) {
            // Persisted as the step's failure, not an illegal transition.
        }

        $this->assertSame(StepStatus::Failed->value, StepExecutionModel::query()->where('position', 0)->value('status'));
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_a_finished_window_alone_filters_on_finished_statuses(): void
    {
        // Without a status predicate a finished window is a full table
        // scan — the one shape the filter design forbids.
        $repository = $this->app->make(ExecutionRepository::class);
        $repository->create('App\Sync', steps: ['One']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $repository->list(new ExecutionFilter(finishedBefore: Date::now()->toImmutable()));
        } finally {
            $log = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $sql = strtolower((string) ($log[0]['query'] ?? ''));
        $this->assertStringContainsString('status', $sql, 'The (status, finished_at) index must be able to serve the window.');
        $this->assertStringContainsString(' in (', $sql);
    }

    public function test_abandoned_executions_do_not_starve_the_scans_that_follow(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-22 10:00:00');

        // One stale, abandoned execution, and one scheduled retry due.
        $repository = $this->app->make(ExecutionRepository::class);
        $abandoned = $this->runner()->start(new TwoStepWorkflow);
        $repository->claim($abandoned->id, 'dead', 300);
        StepExecutionModel::query()->where('workflow_execution_id', $abandoned->id)->update(['attempts' => 3]);

        $retrying = $repository->create(TwoStepWorkflow::class, steps: [StepOne::class, StepTwo::class]);
        WorkflowExecutionModel::query()->whereKey($retrying->id)->update([
            'status' => ExecutionStatus::Retrying->value,
            'next_retry_at' => Date::now(),
        ]);

        Date::setTestNow('2026-10-22 10:30:00');

        $candidates = $this->app->make(RecoveryService::class)->scan(300, null, limit: 1, abandonAfterAttempts: 3);

        $reasons = array_map(fn ($c) => $c->reason, $candidates);
        $this->assertContains(WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM, $reasons, 'The abandoned one is still reported.');
        $this->assertContains(WorkflowExecutionRetryScheduled::REASON_RETRY_DUE, $reasons, 'The due retry is still found despite a limit of one.');
    }

    public function test_an_abandoned_stale_execution_can_be_cancelled_outright(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');
        $execution = $this->runner()->start(new TwoStepWorkflow);
        $this->app->make(ExecutionRepository::class)->claim($execution->id, 'dead', 300);

        Date::setTestNow('2026-10-22 10:30:00');

        $cancelled = $this->runner()->cancel($execution->id, 'crash-looping deploy');

        $this->assertTrue($cancelled->isCancelled(), 'A stale claim is unclaimed by contract; cancellation must not wait for a worker that is gone.');
        $this->assertNull(WorkflowExecutionModel::query()->sole()->claim_token);
    }

    public function test_a_signal_payload_is_bounded_at_signal_time(): void
    {
        $runner = new WorkflowRunner(
            repository: $this->app->make(ExecutionRepository::class),
            container: $this->app,
            logger: new ArrayLogger,
            options: new RunnerOptions(staleAfterSeconds: 300, maxPayloadBytes: 64),
        );
        $execution = $runner->run(new ApprovalWorkflow);

        $this->expectException(PayloadTooLargeException::class);
        $runner->signal($execution->id, 'manager-approval', ['note' => str_repeat('x', 200)]);
    }

    public function test_the_now_override_resumes_a_timed_wait_early(): void
    {
        Date::setTestNow('2026-10-28 10:00:00');
        $parked = $this->runner()->run(new PausedWorkflow);

        $resumed = $this->runner()->execute($parked->id, new PausedWorkflow, ignoreRetrySchedule: true);

        $this->assertTrue($resumed->isCompleted());
        $this->assertSame(1, PauseStep::$runs, 'The step completes; its body does not re-run.');
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
