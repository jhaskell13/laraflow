<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

class WorkflowRunnerPersistenceTest extends TestCase
{
    public function test_it_persists_failed_step_and_workflow_execution(): void
    {
        $runner = app(WorkflowRunner::class);

        try {
            $runner->run(new FailingWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $execution = WorkflowExecutionModel::query()
            ->with('steps')
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame(ExecutionStatus::Failed->value, $execution->status);
        $this->assertNotNull($execution->failed_at);
        $this->assertSame('Something went wrong.', $execution->error);
        $this->assertSame(RuntimeException::class, $execution->error_class);
        $this->assertNull($execution->claim_token);

        $this->assertCount(1, $execution->steps);
        $this->assertSame(StepStatus::Failed->value, $this->stepAt($execution, 0)->status);
        $this->assertSame('Something went wrong.', $this->stepAt($execution, 0)->error);
        $this->assertSame(RuntimeException::class, $this->stepAt($execution, 0)->error_class);
        $this->assertSame(1, $this->stepAt($execution, 0)->attempts);
    }

    public function test_it_persists_workflow_step_state_outputs_and_context(): void
    {
        $runner = app(WorkflowRunner::class);

        $result = $runner->run(new TestWorkflow);

        $this->assertTrue($result->isCompleted());

        $execution = WorkflowExecutionModel::query()
            ->with('steps')
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame(ExecutionStatus::Completed->value, $execution->status);
        $this->assertSame(TestWorkflow::class, $execution->workflow);
        $this->assertNotNull($execution->completed_at);
        $this->assertNull($execution->claim_token);

        $this->assertCount(2, $execution->steps);
        $this->assertSame(TestStepOne::class, $this->stepAt($execution, 0)->step);
        $this->assertSame(TestStepTwo::class, $this->stepAt($execution, 1)->step);
        $this->assertSame(StepStatus::Completed->value, $this->stepAt($execution, 0)->status);
        $this->assertSame(StepStatus::Completed->value, $this->stepAt($execution, 1)->status);

        // Step outputs are persisted at each checkpoint...
        $this->assertSame(['result' => 'one'], $this->stepAt($execution, 0)->output);
        $this->assertSame(['result' => 'two'], $this->stepAt($execution, 1)->output);

        // ...and so is the accumulated context, both on the row and on the
        // returned handle.
        // assertEquals: MySQL's JSON format reorders object keys.
        $this->assertEquals(
            ['step_one' => 'one', 'step_two' => 'two'],
            $execution->context
        );
        $this->assertSame('one', $result->context['step_one']);
        $this->assertSame('two', $result->context['step_two']);
    }
}

class TestWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            TestStepOne::class,
            TestStepTwo::class,
        ];
    }
}

class TestStepOne implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $context->set('step_one', 'one');

        return ['result' => 'one'];
    }
}

class TestStepTwo implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $context->set('step_two', 'two');

        return ['result' => 'two'];
    }
}

class FailingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            FailingStep::class,
        ];
    }
}

class FailingStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        throw new RuntimeException('Something went wrong.');
    }
}
