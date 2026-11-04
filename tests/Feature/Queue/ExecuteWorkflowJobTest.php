<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Queue;

use Illuminate\Support\Facades\Date;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ExecuteWorkflowJobTest extends TestCase
{
    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);
    }

    // ------------------------------------------------------------------
    // Normal execution
    // ------------------------------------------------------------------

    public function test_the_job_executes_a_pending_execution_to_completion(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        $this->work($execution->id);

        $model = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Completed->value, $model->status);
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
    }

    public function test_the_job_resolves_the_workflow_from_the_execution_record(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        // Nothing but the id travels in the payload.
        $job = new ExecuteWorkflowJob($execution->id);
        $this->assertSame($execution->id, $job->executionId);

        $this->app->call([$job, 'handle']);

        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    public function test_an_operator_queued_re_run_recovers_a_failed_execution_from_its_last_checkpoint(): void
    {
        StepTwo::$failTimes = 1;

        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);

        try {
            $runner->execute($execution->id, new TwoStepWorkflow);
            $this->fail('The step exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        // The opt-in Handoff gives an operator's queued re-run.
        $this->app->call([$this->operatorRerun($execution->id), 'handle']);

        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );

        // Step one was checkpointed and is not run again.
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(2, StepTwo::$runs);
    }

    // ------------------------------------------------------------------
    // Duplicate and late deliveries
    // ------------------------------------------------------------------

    public function test_a_duplicate_delivery_does_not_re_run_a_terminally_failed_execution(): void
    {
        // The Milestone 10 audit's HIGH finding: the claim accepted
        // `failed` unconditionally, so the broker's second copy of the
        // delivery that failed the execution ran it all over again.
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        try {
            $this->work($execution->id);
            $this->fail('The step exception should have propagated.');
        } catch (RuntimeException) {
            // The first delivery fails the execution terminally.
        }

        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);

        $this->work($execution->id);

        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs, 'The duplicate delivery must run nothing.');
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
        $this->assertTrue($this->logger->hasRecordContaining('info', 'already finished'));
    }

    public function test_a_duplicate_of_an_operator_re_run_does_not_re_run_the_next_failure(): void
    {
        StepTwo::$failTimes = 2;

        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        try {
            $this->work($execution->id);
        } catch (RuntimeException) {
            // First failure.
        }

        $rerun = $this->operatorRerun($execution->id);

        // The clock moves on, so the re-run's failure is a different one.
        Date::setTestNow(Date::now()->addMinute());

        try {
            $this->app->call([$rerun, 'handle']);
            $this->fail('The re-run fails again and should propagate.');
        } catch (RuntimeException) {
            // The operator's re-run ran, and failed again.
        }

        $this->assertSame(2, StepTwo::$runs);

        // The broker delivers the operator's job a second time.
        $this->app->call([$rerun, 'handle']);

        $this->assertSame(2, StepTwo::$runs, 'The opt-in names one failure, not every later one.');
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_a_duplicate_delivery_runs_the_steps_exactly_once(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        $this->work($execution->id);
        $this->work($execution->id);

        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);

        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );

        $this->assertTrue($this->logger->hasRecordContaining(
            'info',
            'already finished',
        ));
    }

    public function test_a_delivery_for_a_freshly_claimed_execution_is_a_no_op(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        // Another worker holds a fresh claim.
        $this->app->make(ExecutionRepository::class)
            ->claim($execution->id, str_repeat('ab', 16), 300);

        $this->work($execution->id);

        $this->assertSame(0, StepOne::$runs);

        $this->assertTrue($this->logger->hasRecordContaining(
            'info',
            'duplicate delivery',
        ));
    }

    public function test_a_delivery_for_a_deleted_execution_is_a_no_op(): void
    {
        $this->work(404);

        $this->assertTrue($this->logger->hasRecordContaining(
            'warning',
            'no longer exists',
        ));
    }

    // ------------------------------------------------------------------
    // Failures
    // ------------------------------------------------------------------

    public function test_a_step_failure_propagates_so_the_queue_can_record_it(): void
    {
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        try {
            $this->work($execution->id);
            $this->fail('The step exception should have propagated to the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage());
        }

        // The runner persisted the failure before rethrowing.
        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $model->status);
        $this->assertSame('step two blew up', $model->error);
    }

    public function test_a_missing_workflow_class_fails_the_job_with_a_clear_message(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'workflow' => 'App\\Workflows\\DeletedWorkflow',
        ]);

        $this->expectException(WorkflowDefinitionMismatchException::class);
        $this->expectExceptionMessage('no longer exists or is not a workflow');

        $this->work($execution->id);
    }

    public function test_a_workflow_column_naming_a_non_workflow_class_fails_the_job(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'workflow' => StepOne::class,
        ]);

        $this->expectException(WorkflowDefinitionMismatchException::class);

        $this->work($execution->id);
    }

    public function test_losing_the_claim_mid_run_is_not_a_job_failure(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);

        // A step that hands the execution to another worker while running,
        // exactly as a stale-claim takeover would.
        StepOne::$beforeReturn = function () use ($execution): void {
            Date::setTestNow('2026-10-22 10:30:00');

            $this->app->make(ExecutionRepository::class)
                ->claim($execution->id, str_repeat('cd', 16), 300);
        };

        $this->work($execution->id);

        $this->assertTrue($this->logger->hasRecordContaining(
            'warning',
            'lost an execution claim',
        ));

        // Nothing this worker did was persisted.
        $this->assertSame(
            StepStatus::Running->value,
            WorkflowExecutionModel::query()->sole()->steps()->orderBy('position')->first()?->status,
        );
    }

    // ------------------------------------------------------------------
    // Queue settings
    // ------------------------------------------------------------------

    public function test_a_blank_setting_means_the_default_not_zero(): void
    {
        // A stray empty env line reaches config as ''. (int) '' is 0,
        // and $tries = 0 is Laravel for *unlimited* queue retries — the
        // exact authority this package promises the queue never has.
        config([
            'laraflow.queue.tries' => '',
            'laraflow.queue.timeout' => '',
            'laraflow.queue.backoff' => '',
        ]);

        $job = new ExecuteWorkflowJob(1);

        $this->assertSame(1, $job->tries, 'Blank must mean the default, never unlimited retries.');
        $this->assertSame(60, $job->timeout);
        $this->assertSame(0, $job->backoff);
    }

    public function test_the_job_reads_queue_settings_from_configuration(): void
    {
        config([
            'laraflow.queue.tries' => 5,
            'laraflow.queue.timeout' => 90,
            'laraflow.queue.backoff' => 15,
        ]);

        $job = new ExecuteWorkflowJob(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame(90, $job->timeout);
        $this->assertSame(15, $job->backoff);
    }

    public function test_the_job_defaults_to_a_single_attempt(): void
    {
        $this->assertSame(1, (new ExecuteWorkflowJob(1))->tries);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The job WorkflowDispatcher builds for an operator's re-run of the
     * execution's current failure.
     */
    protected function operatorRerun(int $executionId): ExecuteWorkflowJob
    {
        $failedAt = $this->app->make(ExecutionRepository::class)->find($executionId)->failedAt;
        $this->assertNotNull($failedAt);

        return new ExecuteWorkflowJob($executionId, $failedAt->format('Y-m-d H:i:s'));
    }

    protected function work(int $executionId): void
    {
        $this->app->call([new ExecuteWorkflowJob($executionId), 'handle']);
    }
}
