<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\BadOutputWorkflow;
use Laraflow\Tests\Support\CrashingExecutionRepository;
use Laraflow\Tests\Support\MutableWorkflow;
use Laraflow\Tests\Support\SimulatedWorkerCrash;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;
use stdClass;

class WorkflowRecoveryTest extends TestCase
{
    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new ArrayLogger;
    }

    // ------------------------------------------------------------------
    // start() / execute() separation
    // ------------------------------------------------------------------

    public function test_start_persists_the_execution_and_the_full_step_plan_without_running(): void
    {
        $execution = $this->runner()->start(new TwoStepWorkflow, ['customer_id' => 42]);

        $this->assertTrue($execution->isPending());
        $this->assertSame(['customer_id' => 42], $execution->input);
        $this->assertSame(0, StepOne::$runs);

        $steps = StepExecutionModel::query()->orderBy('position')->get();

        $this->assertCount(2, $steps);
        $this->assertSame(StepOne::class, $steps[0]->step);
        $this->assertSame(StepTwo::class, $steps[1]->step);
        $this->assertSame(StepStatus::Pending->value, $steps[0]->status);
        $this->assertSame(StepStatus::Pending->value, $steps[1]->status);
    }

    public function test_a_started_execution_can_be_executed_later_without_duplicating_records(): void
    {
        $runner = $this->runner();
        $started = $runner->start(new TwoStepWorkflow);

        $finished = $runner->execute($started->id, new TwoStepWorkflow);

        $this->assertSame($started->id, $finished->id);
        $this->assertTrue($finished->isCompleted());
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
        $this->assertSame(2, StepExecutionModel::query()->count());
    }

    public function test_input_seeds_the_context_and_is_restored_from_durable_state(): void
    {
        $execution = $this->runner()->run(new TwoStepWorkflow, ['customer_id' => 42]);

        $this->assertSame(42, $execution->context['customer_id']);
        $this->assertSame('from-step-one', $execution->context['one']);
        $this->assertSame('from-step-two', $execution->context['two']);
    }

    public function test_timestamps_come_from_laravels_clock_so_tests_can_freeze_time(): void
    {
        Date::setTestNow('2026-10-22 12:00:00');

        try {
            $execution = $this->runner()->run(new TwoStepWorkflow);

            $this->assertSame('2026-10-22 12:00:00', $execution->startedAt?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-22 12:00:00', $execution->completedAt?->format('Y-m-d H:i:s'));

            $step = StepExecutionModel::query()->first();
            $this->assertSame('2026-10-22 12:00:00', $step?->started_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-22 12:00:00', $step?->completed_at?->format('Y-m-d H:i:s'));
        } finally {
            Date::setTestNow();
        }
    }

    // ------------------------------------------------------------------
    // Definition validation at start()
    // ------------------------------------------------------------------

    public function test_a_workflow_with_no_steps_is_rejected_before_persisting(): void
    {
        MutableWorkflow::$steps = [];

        try {
            $this->runner()->start(new MutableWorkflow);
            $this->fail('Expected InvalidWorkflowDefinitionException.');
        } catch (InvalidWorkflowDefinitionException $exception) {
            $this->assertStringContainsString('defines no steps', $exception->getMessage());
        }

        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_a_step_that_is_not_a_step_class_is_rejected_before_persisting(): void
    {
        MutableWorkflow::$steps = [StepOne::class, stdClass::class];

        try {
            $this->runner()->start(new MutableWorkflow);
            $this->fail('Expected InvalidWorkflowDefinitionException.');
        } catch (InvalidWorkflowDefinitionException $exception) {
            $this->assertStringContainsString('position [1]', $exception->getMessage());
            $this->assertStringContainsString(stdClass::class, $exception->getMessage());
        }

        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_a_step_class_that_does_not_exist_is_rejected_before_persisting(): void
    {
        MutableWorkflow::$steps = ['App\\Workflows\\Steps\\DoesNotExist'];

        $this->expectException(InvalidWorkflowDefinitionException::class);

        $this->runner()->start(new MutableWorkflow);
    }

    // ------------------------------------------------------------------
    // Failure and retry
    // ------------------------------------------------------------------

    public function test_a_failed_execution_resumes_by_skipping_completed_steps_and_restoring_context(): void
    {
        StepTwo::$failTimes = 1;
        $runner = $this->runner();

        try {
            $runner->run(new TwoStepWorkflow);
            $this->fail('Expected the step failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage());
        }

        $model = WorkflowExecutionModel::query()->firstOrFail();
        $this->assertSame(ExecutionStatus::Failed->value, $model->status);
        $this->assertSame('step two blew up', $model->error);
        $this->assertSame(RuntimeException::class, $model->error_class);
        $this->assertNull($model->claim_token);

        // Recover. Step one must not run again; step two must see step
        // one's context value even though it was written by a dead "process".
        StepTwo::$seenContextValue = null;
        $execution = $runner->execute($model->id, new TwoStepWorkflow);

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(2, StepTwo::$runs);
        $this->assertSame('from-step-one', StepTwo::$seenContextValue);

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertSame(1, $steps[0]->attempts);
        $this->assertSame(2, $steps[1]->attempts);
        $this->assertNull($steps[1]->error);
        $this->assertSame(ExecutionStatus::Completed->value, $model->fresh()?->status);
    }

    public function test_the_idempotency_key_is_stable_across_attempts_of_the_same_step(): void
    {
        StepTwo::$failTimes = 1;
        $runner = $this->runner();

        try {
            $runner->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $id = WorkflowExecutionModel::query()->value('id');
        $runner->execute($id, new TwoStepWorkflow);

        $this->assertCount(2, StepTwo::$seenIdempotencyKeys);
        $this->assertSame("laraflow:{$id}:1", StepTwo::$seenIdempotencyKeys[0]);
        $this->assertSame(StepTwo::$seenIdempotencyKeys[0], StepTwo::$seenIdempotencyKeys[1]);
    }

    public function test_error_messages_are_truncated_to_the_configured_length(): void
    {
        StepTwo::$failTimes = 1;

        $runner = $this->runner(maxErrorLength: 8);

        try {
            $runner->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame('step two', WorkflowExecutionModel::query()->value('error'));
    }

    // ------------------------------------------------------------------
    // Crash boundaries (simulated process death; nothing persists after it)
    // ------------------------------------------------------------------

    public function test_crash_before_a_step_is_marked_running_recovers_cleanly(): void
    {
        try {
            $this->crashingRunner('saveStep', call: 1, phase: 'before')->run(new TwoStepWorkflow);
            $this->fail('Expected the simulated crash to propagate.');
        } catch (SimulatedWorkerCrash) {
            // Worker died.
        }

        $this->assertSame(0, StepOne::$runs);
        $this->assertSame(
            StepStatus::Pending->value,
            StepExecutionModel::query()->where('position', 0)->value('status')
        );

        $execution = $this->runner(staleAfter: 0)->execute(
            WorkflowExecutionModel::query()->value('id'),
            new TwoStepWorkflow
        );

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
    }

    public function test_crash_between_side_effect_and_checkpoint_reruns_the_interrupted_step(): void
    {
        // The step's handle() ran (side effect happened) but the worker died
        // before the checkpoint persisted. This is the documented
        // at-least-once boundary: recovery must run the step again.
        try {
            $this->crashingRunner('checkpoint', call: 1, phase: 'before')->run(new TwoStepWorkflow);
            $this->fail('Expected the simulated crash to propagate.');
        } catch (SimulatedWorkerCrash) {
            // Worker died.
        }

        $stale = StepExecutionModel::query()->where('position', 0)->firstOrFail();
        $this->assertSame(StepStatus::Running->value, $stale->status);
        $this->assertSame(1, StepOne::$runs);

        $execution = $this->runner(staleAfter: 0)->execute(
            WorkflowExecutionModel::query()->value('id'),
            new TwoStepWorkflow
        );

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(2, StepOne::$runs, 'The interrupted step must run again.');
        $this->assertSame(2, $stale->fresh()?->attempts);
    }

    public function test_crash_after_a_checkpoint_skips_the_completed_step_on_recovery(): void
    {
        try {
            $this->crashingRunner('checkpoint', call: 1, phase: 'after')->run(new TwoStepWorkflow);
            $this->fail('Expected the simulated crash to propagate.');
        } catch (SimulatedWorkerCrash) {
            // Worker died.
        }

        $this->assertSame(
            StepStatus::Completed->value,
            StepExecutionModel::query()->where('position', 0)->value('status')
        );

        $execution = $this->runner(staleAfter: 0)->execute(
            WorkflowExecutionModel::query()->value('id'),
            new TwoStepWorkflow
        );

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(1, StepOne::$runs, 'The checkpointed step must not run again.');
        $this->assertSame(1, StepTwo::$runs);
        $this->assertSame('from-step-one', StepTwo::$seenContextValue);
    }

    public function test_crash_before_the_final_release_completes_without_rerunning_steps(): void
    {
        try {
            $this->crashingRunner('release', call: 1, phase: 'before')->run(new TwoStepWorkflow);
            $this->fail('Expected the simulated crash to propagate.');
        } catch (SimulatedWorkerCrash) {
            // Worker died with every step checkpointed but the execution
            // still marked running.
        }

        $this->assertSame(
            ExecutionStatus::Running->value,
            WorkflowExecutionModel::query()->value('status')
        );

        $execution = $this->runner(staleAfter: 0)->execute(
            WorkflowExecutionModel::query()->value('id'),
            new TwoStepWorkflow
        );

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
    }

    // ------------------------------------------------------------------
    // Claiming
    // ------------------------------------------------------------------

    public function test_a_freshly_claimed_execution_cannot_be_claimed_by_another_worker(): void
    {
        $execution = $this->runner()->start(new TwoStepWorkflow);

        $this->app->make(ExecutionRepository::class)->claim($execution->id, 'other-worker', 300);

        $this->expectException(ExecutionAlreadyClaimedException::class);

        $this->runner()->execute($execution->id, new TwoStepWorkflow);
    }

    public function test_a_stale_claim_can_be_taken_over(): void
    {
        $execution = $this->runner()->start(new TwoStepWorkflow);

        $this->app->make(ExecutionRepository::class)->claim($execution->id, 'dead-worker', 300);

        WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->update(['claimed_at' => Date::now()->subMinutes(30)]);

        $finished = $this->runner(staleAfter: 300)->execute($execution->id, new TwoStepWorkflow);

        $this->assertTrue($finished->isCompleted());
        $this->assertNull(WorkflowExecutionModel::query()->value('claim_token'));
    }

    public function test_a_completed_execution_cannot_be_executed_again(): void
    {
        $runner = $this->runner();
        $execution = $runner->run(new TwoStepWorkflow);

        $this->expectException(ExecutionNotRunnableException::class);

        $runner->execute($execution->id, new TwoStepWorkflow);
    }

    public function test_executing_an_unknown_execution_fails_clearly(): void
    {
        $this->expectException(ExecutionNotFoundException::class);

        $this->runner()->execute(999999, new TwoStepWorkflow);
    }

    // ------------------------------------------------------------------
    // Definition drift
    // ------------------------------------------------------------------

    public function test_a_changed_step_list_is_rejected_instead_of_silently_reinterpreted(): void
    {
        MutableWorkflow::$steps = [StepOne::class, StepTwo::class];
        $runner = $this->runner();
        $execution = $runner->start(new MutableWorkflow);

        // The application deploys a new version of the workflow.
        MutableWorkflow::$steps = [StepTwo::class, StepOne::class];

        $this->expectException(WorkflowDefinitionMismatchException::class);

        $runner->execute($execution->id, new MutableWorkflow);
    }

    public function test_an_execution_cannot_be_run_with_a_different_workflow_class(): void
    {
        MutableWorkflow::$steps = [StepOne::class, StepTwo::class];
        $runner = $this->runner();
        $execution = $runner->start(new TwoStepWorkflow);

        $this->expectException(WorkflowDefinitionMismatchException::class);

        $runner->execute($execution->id, new MutableWorkflow);
    }

    // ------------------------------------------------------------------
    // Serialization contract
    // ------------------------------------------------------------------

    public function test_unserializable_input_is_rejected_before_anything_is_persisted(): void
    {
        try {
            $this->runner()->start(new TwoStepWorkflow, ['customer' => new stdClass]);
            $this->fail('Expected UnserializableValueException.');
        } catch (UnserializableValueException $exception) {
            $this->assertStringContainsString('input.customer', $exception->getMessage());
        }

        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_unserializable_step_output_fails_the_step_with_a_clear_error(): void
    {
        try {
            $this->runner()->run(new BadOutputWorkflow);
            $this->fail('Expected UnserializableValueException.');
        } catch (UnserializableValueException) {
            // Expected.
        }

        $step = StepExecutionModel::query()->firstOrFail();
        $this->assertSame(StepStatus::Failed->value, $step->status);
        $this->assertSame(UnserializableValueException::class, $step->error_class);
        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->value('status')
        );
    }

    // ------------------------------------------------------------------
    // Failure persistence robustness
    // ------------------------------------------------------------------

    public function test_the_original_exception_survives_even_if_persisting_the_failure_fails(): void
    {
        StepTwo::$failTimes = 1;

        // Call sequence: saveStep #1/#2 mark steps running, checkpoint
        // persists step one; saveStep #3 is the attempt to record step two's
        // failure — storage dies there and stays dead for release().
        $runner = $this->crashingRunner('saveStep', call: 3, phase: 'before');

        try {
            $runner->run(new TwoStepWorkflow);
            $this->fail('Expected the original RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage());
        }

        $this->assertTrue(
            $this->logger->hasRecordContaining('error', 'could not persist a workflow failure'),
            'The storage failure must be logged, not swallowed silently.'
        );

        $record = $this->logger->records[0];
        $this->assertStringContainsString('step two blew up', $record['context']['original_exception']);
        $this->assertSame(SimulatedWorkerCrash::class.': Worker died before [saveStep] call #3.', $record['context']['storage_exception']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function runner(int $staleAfter = 300, int $maxErrorLength = 65535): WorkflowRunner
    {
        return new WorkflowRunner(
            repository: $this->app->make(ExecutionRepository::class),
            container: $this->app,
            logger: $this->logger,
            staleAfterSeconds: $staleAfter,
            maxErrorLength: $maxErrorLength,
        );
    }

    protected function crashingRunner(string $dieOn, int $call = 1, string $phase = 'before'): WorkflowRunner
    {
        return new WorkflowRunner(
            repository: new CrashingExecutionRepository(
                $this->app->make(ExecutionRepository::class),
                $dieOn,
                $call,
                $phase,
            ),
            container: $this->app,
            logger: $this->logger,
            staleAfterSeconds: 300,
        );
    }
}
