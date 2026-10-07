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
        $workflow = new class extends Workflow {
            public function steps(): array
            {
                return [
                    FirstTestStep::class,
                    SecondTestStep::class,
                ];
            }
        };

        $runner = app(WorkflowRunner::class);

        $context = $runner->run($workflow);

        $this->assertSame(
            'second',
            $context->get('value')
        );
    }
}

class FirstTestStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $context->set('value', 'first');

        return null;
    }
}

class SecondTestStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $context->set('value', 'second');

        return null;
    }
}