<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepThree;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * FailurePolicy::Continue, for workflows of independent steps: a
 * terminal step failure no longer stops the run, but the execution
 * still ends failed and still throws — the first failure, once the
 * remaining steps have had their chance.
 */
class FailurePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        PolicyWorkflow::$failurePolicy = FailurePolicy::Continue;
    }

    public function test_continue_runs_the_remaining_steps_and_still_fails_with_the_first_exception(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('A continue-on-failure run with a failed step must still throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage());
        }

        // Every step ran despite the failure in the middle.
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
        $this->assertSame(1, StepThree::$runs);

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertSame(StepStatus::Completed->value, $steps[0]->status);
        $this->assertSame(StepStatus::Failed->value, $steps[1]->status);
        $this->assertSame(StepStatus::Completed->value, $steps[2]->status);

        // The execution is failed — continue is not success — and records
        // the first failure.
        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $model->status);
        $this->assertSame('step two blew up', $model->error);
        $this->assertSame(RuntimeException::class, $model->error_class);
        $this->assertNull($model->claim_token);
    }

    public function test_later_steps_see_earlier_checkpoints_but_nothing_from_the_failed_step(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertNull(
            StepThree::$seenContextValueFromStepTwo,
            'The failed step contributed nothing; continue is for independent steps.',
        );

        $context = WorkflowExecutionModel::query()->sole()->context;
        $this->assertSame('from-step-one', $context['one'] ?? null);
        $this->assertSame('from-step-three', $context['three'] ?? null);
        $this->assertArrayNotHasKey('two', $context ?? []);
    }

    public function test_retry_policy_takes_precedence_over_continuing(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 0, jitter: 0.0);
        StepTwo::$failTimes = 1;
        $runner = $this->runner();

        // The first failure schedules a retry; nothing continues yet.
        $execution = $runner->run(new PolicyWorkflow);

        $this->assertTrue($execution->isRetrying());
        $this->assertSame(0, StepThree::$runs, 'A healable failure must heal before anything continues past it.');

        // The due retry succeeds and the whole workflow completes.
        $finished = $runner->execute($execution->id, new PolicyWorkflow);

        $this->assertTrue($finished->isCompleted());
        $this->assertSame(1, StepThree::$runs);
    }

    public function test_an_exhausted_retry_budget_under_continue_fails_the_step_and_keeps_going(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 0, jitter: 0.0);
        StepTwo::$failTimes = 99;
        $runner = $this->runner();

        $execution = $runner->run(new PolicyWorkflow);
        $this->assertTrue($execution->isRetrying(), 'Attempt 1 of 2 schedules.');

        try {
            $runner->execute($execution->id, new PolicyWorkflow);
            $this->fail('Expected the step exception once the budget was spent.');
        } catch (RuntimeException) {
            // Expected: attempt 2 of 2 is terminal.
        }

        $this->assertSame(2, StepTwo::$runs);
        $this->assertSame(1, StepThree::$runs, 'The budget-spent step no longer blocks its siblings.');
        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    public function test_multiple_failures_are_each_recorded_but_the_first_is_thrown(): void
    {
        PolicyWorkflow::$steps = [StepTwo::class, StepOne::class, \Laraflow\Tests\Support\BadOutputStep::class];
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('Expected the first failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage(), 'First failure wins.');
        }

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertSame(StepStatus::Failed->value, $steps[0]->status);
        $this->assertSame(StepStatus::Completed->value, $steps[1]->status);
        $this->assertSame(StepStatus::Failed->value, $steps[2]->status, 'The second failure is recorded too.');

        $this->assertSame(
            'step two blew up',
            WorkflowExecutionModel::query()->sole()->error,
        );
    }

    public function test_event_order_each_step_failure_announced_execution_failed_once_at_the_end(): void
    {
        StepTwo::$failTimes = 1;

        Event::fake([WorkflowStepFailed::class, WorkflowExecutionFailed::class, WorkflowExecutionCompleted::class]);

        try {
            $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        Event::assertDispatchedTimes(WorkflowStepFailed::class, 1);
        Event::assertDispatchedTimes(WorkflowExecutionFailed::class, 1);
        Event::assertNotDispatched(WorkflowExecutionCompleted::class);
    }

    public function test_re_executing_a_continue_failure_re_runs_only_the_failed_steps(): void
    {
        StepTwo::$failTimes = 1;
        $runner = $this->runner();

        try {
            $runner->run(new PolicyWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $finished = $runner->execute(WorkflowExecutionModel::query()->value('id'), new PolicyWorkflow);

        $this->assertTrue($finished->isCompleted());
        $this->assertSame(1, StepOne::$runs, 'Checkpointed steps stay skipped.');
        $this->assertSame(2, StepTwo::$runs, 'Only the failed step re-ran.');
        $this->assertSame(1, StepThree::$runs);
        $this->assertSame('from-step-two', $finished->context['two'] ?? null, 'The retry finally contributed.');
    }

    public function test_the_default_stop_policy_is_unchanged(): void
    {
        PolicyWorkflow::$failurePolicy = FailurePolicy::Stop;
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, StepThree::$runs);
        $this->assertSame(
            StepStatus::Pending->value,
            StepExecutionModel::query()->where('position', 2)->sole()->status,
            'Under stop, later steps wait for a successful re-execution.',
        );
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
