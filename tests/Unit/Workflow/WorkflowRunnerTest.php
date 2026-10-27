<?php

declare(strict_types=1);

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
        $runner = $this->app->make(WorkflowRunner::class);

        $execution = $runner->run(new OrderedWorkflow);

        $this->assertTrue($execution->isCompleted());
        $this->assertSame('second', $execution->context['value']);
        $this->assertSame(['first', 'second'], $execution->context['order']);
    }

    public function test_steps_receive_workflow_input_through_the_context(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        $execution = $runner->run(new OrderedWorkflow, ['customer_id' => 42]);

        $this->assertSame(42, $execution->context['seen_customer_id']);
    }

    public function test_steps_are_resolved_from_the_container_with_their_dependencies(): void
    {
        $this->app->instance(GreetingService::class, new GreetingService('Hello from the container'));

        $runner = $this->app->make(WorkflowRunner::class);

        $execution = $runner->run(new DependencyWorkflow);

        $this->assertSame('Hello from the container', $execution->context['greeting']);
    }

    public function test_the_runner_is_a_container_singleton_configured_from_config(): void
    {
        $first = $this->app->make(WorkflowRunner::class);
        $second = $this->app->make(WorkflowRunner::class);

        $this->assertSame($first, $second);
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

class GreetingService
{
    public function __construct(public readonly string $greeting) {}
}

class DependencyWorkflow extends Workflow
{
    public function steps(): array
    {
        return [GreetingStep::class];
    }
}

class GreetingStep implements Step
{
    public function __construct(private readonly GreetingService $service) {}

    public function handle(WorkflowContext $context): mixed
    {
        $context->set('greeting', $this->service->greeting);

        return null;
    }
}
