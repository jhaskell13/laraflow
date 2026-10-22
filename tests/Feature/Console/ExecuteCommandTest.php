<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Symfony\Component\Console\Command\Command as CommandStatus;

class ExecuteCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_it_recovers_a_failed_demo_execution_from_its_checkpoint(): void
    {
        $this->callCommand('laraflow:demo', ['--fail' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => '1']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Completed steps will be skipped', $output);
        $this->assertStringContainsString('Execution completed', $output);

        $execution = WorkflowExecutionModel::query()->firstOrFail();
        $this->assertSame(ExecutionStatus::Completed->value, $execution->status);
        $this->assertArrayHasKey('receipt', $execution->context);

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertSame(1, $steps[0]->attempts, 'The checkpointed step must not re-run.');
        $this->assertSame(2, $steps[1]->attempts, 'The failed step retries on attempt 2.');
        $this->assertNull($steps[1]->error);

        // The key the retry used is the same logical key the failed first
        // attempt would have sent — that is what makes retries deduplicable.
        $this->assertSame('laraflow:1:1', $steps[1]->output['idempotency_key'] ?? null);
        $this->assertSame(2, $steps[1]->output['succeeded_on_attempt'] ?? null);
    }

    public function test_an_unknown_execution_id_fails_with_a_clear_message(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => '999']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('was not found', $output);
    }

    public function test_a_completed_execution_is_refused(): void
    {
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => '1']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('cannot be executed from status [completed]', $output);
    }

    public function test_an_execution_whose_workflow_class_is_gone_is_refused(): void
    {
        $this->app->make(ExecutionRepository::class)->create(
            workflow: 'App\\Workflows\\DeletedWorkflow',
            steps: ['App\\Workflows\\Steps\\DeletedStep'],
        );

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => '1']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('no longer exists', $output);

        $this->assertSame(
            ExecutionStatus::Pending->value,
            WorkflowExecutionModel::query()->value('status'),
            'Refusing to execute must not change durable state.'
        );
    }
}
