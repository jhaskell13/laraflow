<?php

namespace Laraflow\Tests\Unit\Workflow;

use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;
use Laraflow\Workflow\WorkflowRunner;

class WorkflowRunnerTest extends TestCase
{
    public function test_it_runs_workflow_steps_in_order(): void
    {
        $runner = app(WorkflowRunner::class);

        $execution = $runner->run(new OrderedWorkflow());

        $this->assertTrue($execution->isCompleted());
        $this->assertSame('second', $execution->context['value']);
        $this->assertSame(['first', 'second'], $execution->context['order']);
    }

    public function test_steps_receive_workflow_input_through_the_context(): void
    {
        $runner = app(WorkflowRunner::class);

        $execution = $runner->run(new OrderedWorkflow(), ['customer_id' => 42]);

        $this->assertSame(42, $execution->context['seen_customer_id']);
    }
}

class OrderedWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            FirstTestStep::class,
            SecondTestStep::class,
        ];
    }
}

class FirstTestStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $context->set('value', 'first');
        $context->set('order', ['first']);
        $context->set('seen_customer_id', $context->get('customer_id'));

        return null;
    }
}

class SecondTestStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $context->set('value', 'second');
        $context->set('order', [...$context->get('order', []), 'second']);

        return null;
    }
}
