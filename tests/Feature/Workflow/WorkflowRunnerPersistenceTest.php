<?php

namespace Laraflow\Tests\Feature\Workflow;

use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;
use Laraflow\Workflow\WorkflowRunner;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;

class WorkflowRunnerPersistenceTest extends TestCase
{
    public function test_it_persists_failed_step_and_workflow_execution(): void
    {
        $runner = app(WorkflowRunner::class);

        try {
            $runner->run(new FailingWorkflow());
        } catch (\RuntimeException) {
            // Expected.
        }

        $execution = WorkflowExecutionModel::query()
            ->with('steps')
            ->first();

        $this->assertNotNull($execution);

        $this->assertSame(
            ExecutionStatus::Failed->value,
            $execution->status
        );

        $this->assertNotNull($execution->failed_at);

        $this->assertCount(1, $execution->steps);

        $this->assertSame(
            StepStatus::Failed->value,
            $execution->steps[0]->status
        );

        $this->assertSame(
            'Something went wrong.',
            $execution->steps[0]->error
        );
    }

    public function test_it_persists_workflow_and_step_execution_state(): void
    {
        $runner = app(WorkflowRunner::class);

        $context = $runner->run(new TestWorkflow());

        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->first()->status
        );

        $execution = WorkflowExecutionModel::query()
            ->with('steps')
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame(
            TestWorkflow::class,
            $execution->workflow
        );

        $this->assertCount(2, $execution->steps);

        $this->assertSame(
            TestStepOne::class,
            $execution->steps[0]->step
        );

        $this->assertSame(
            TestStepTwo::class,
            $execution->steps[1]->step
        );

        $this->assertSame(
            StepStatus::Completed->value,
            $execution->steps[0]->status
        );

        $this->assertSame(
            StepStatus::Completed->value,
            $execution->steps[1]->status
        );

        $this->assertSame(
            'one',
            $context->get('step_one')
        );

        $this->assertSame(
            'two',
            $context->get('step_two')
        );
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
        throw new \RuntimeException('Something went wrong.');
    }
}