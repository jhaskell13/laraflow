<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\PayloadTooLargeException;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;

/**
 * The payload size policy: off by default, and when set, enforced at
 * the same three boundaries as the serialization contract — input at
 * start(), output and context before each checkpoint.
 */
class PayloadLimitTest extends TestCase
{
    public function test_no_limit_is_enforced_by_default(): void
    {
        $this->assertNull(config('laraflow.execution.max_payload_bytes'));

        $execution = $this->runner()->run(new TwoStepWorkflow, ['blob' => str_repeat('a', 200_000)]);

        $this->assertTrue($execution->isCompleted());
    }

    // ------------------------------------------------------------------
    // Input, at start()
    // ------------------------------------------------------------------

    public function test_oversized_input_is_rejected_before_anything_is_persisted(): void
    {
        $this->limit(500);

        try {
            $this->runner()->start(new TwoStepWorkflow, ['blob' => str_repeat('a', 1000)]);
            $this->fail('Expected the input to be rejected.');
        } catch (PayloadTooLargeException $exception) {
            $this->assertStringContainsString('[input]', $exception->getMessage());
            $this->assertStringContainsString('1,0', $exception->getMessage(), 'The actual size is named.');
            $this->assertStringContainsString('500', $exception->getMessage(), 'The limit is named.');
        }

        // Same guarantee as the serialization contract: a rejected
        // input leaves no execution and no step plan behind.
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
        $this->assertSame(0, StepExecutionModel::query()->count());
    }

    public function test_input_within_the_limit_is_accepted(): void
    {
        $this->limit(500);

        $execution = $this->runner()->run(new TwoStepWorkflow, ['note' => 'small']);

        $this->assertTrue($execution->isCompleted());
    }

    // ------------------------------------------------------------------
    // Output and context, at the checkpoint
    // ------------------------------------------------------------------

    public function test_an_oversized_step_output_fails_the_step(): void
    {
        $this->limit(500);

        StepOne::$output = ['blob' => str_repeat('a', 1000)];

        try {
            $this->runner()->run(new TwoStepWorkflow);
            $this->fail('Expected the output to be rejected.');
        } catch (PayloadTooLargeException $exception) {
            $this->assertStringContainsString('[output]', $exception->getMessage());
        }

        $step = StepExecutionModel::query()->where('position', 0)->sole();

        $this->assertSame(StepStatus::Failed->value, $step->status);
        $this->assertSame(PayloadTooLargeException::class, $step->error_class);

        // Checked before the checkpoint, so nothing oversized reached
        // the column.
        $this->assertNull($step->output);
    }

    public function test_an_oversized_context_fails_the_step_that_grew_it(): void
    {
        $this->limit(400);

        StepTwo::$beforeRun = function (): void {
            // Output stays small; it is the accumulated context that
            // crosses the limit.
            StepTwo::$contextValue = str_repeat('b', 1000);
        };

        try {
            $this->runner()->run(new TwoStepWorkflow);
            $this->fail('Expected the context to be rejected.');
        } catch (PayloadTooLargeException $exception) {
            $this->assertStringContainsString('[context]', $exception->getMessage());
        }

        $this->assertSame(
            StepStatus::Failed->value,
            StepExecutionModel::query()->where('position', 1)->sole()->status,
        );
    }

    public function test_an_earlier_checkpoint_survives_a_later_oversized_step(): void
    {
        $this->limit(600);

        StepTwo::$beforeRun = function (): void {
            StepTwo::$contextValue = str_repeat('b', 2000);
        };

        try {
            $this->runner()->run(new TwoStepWorkflow);
        } catch (PayloadTooLargeException) {
            // Expected.
        }

        // The first step's checkpoint committed before the limit was
        // ever crossed, and re-executing must still skip it.
        $this->assertSame(
            StepStatus::Completed->value,
            StepExecutionModel::query()->where('position', 0)->sole()->status,
        );
        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    // ------------------------------------------------------------------
    // Interaction with retry policy
    // ------------------------------------------------------------------

    public function test_an_oversized_payload_is_never_retried(): void
    {
        $this->limit(500);

        PolicyWorkflow::$steps = [StepOne::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 5, baseDelaySeconds: 0, jitter: 0.0);
        StepOne::$output = ['blob' => str_repeat('a', 1000)];

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('Expected a terminal failure.');
        } catch (PayloadTooLargeException) {
            // Expected: the budget is irrelevant.
        }

        // The same step will produce the same size, so scheduling four
        // more attempts would only fill the queue with work that
        // cannot succeed.
        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
        $this->assertSame(1, StepOne::$runs);
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function test_a_blank_environment_value_means_no_limit(): void
    {
        // A stray `LARAFLOW_MAX_PAYLOAD_BYTES=` must not cast to 0 and
        // reject every payload the application has.
        config(['laraflow.execution.max_payload_bytes' => '']);
        $this->app->forgetInstance(WorkflowRunner::class);

        $execution = $this->runner()->run(new TwoStepWorkflow, ['blob' => str_repeat('a', 50_000)]);

        $this->assertTrue($execution->isCompleted());
    }

    public function test_a_zero_limit_is_rejected_at_construction(): void
    {
        $this->limit(0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 byte');

        $this->runner();
    }

    protected function limit(int $bytes): void
    {
        config(['laraflow.execution.max_payload_bytes' => $bytes]);
        $this->app->forgetInstance(WorkflowRunner::class);
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
