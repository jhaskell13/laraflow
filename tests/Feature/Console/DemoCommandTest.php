<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Queue;
use Laraflow\Demo\DemoWorkflow;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;
use Symfony\Component\Console\Command\Command as CommandStatus;

class DemoCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_it_runs_the_demo_workflow_to_completion(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:demo');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString(DemoWorkflow::class, $output);
        $this->assertStringContainsString('ReserveInventory', $output);
        $this->assertStringContainsString('SendReceipt', $output);
        $this->assertStringContainsString('laraflow:show 1', $output);

        $execution = WorkflowExecutionModel::query()->firstOrFail();

        $this->assertSame(ExecutionStatus::Completed->value, $execution->status);
        $this->assertSame('RES-1-SKU-1001', $execution->context['reservation_id'] ?? null);
        $this->assertArrayHasKey('receipt', $execution->context);
        $this->assertSame(3, StepExecutionModel::query()->where('status', StepStatus::Completed->value)->count());
    }

    public function test_the_fail_flag_leaves_a_recoverable_failed_execution(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:demo', ['--fail' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('Simulated payment gateway timeout', $output);
        $this->assertStringContainsString('laraflow:execute 1', $output);

        $execution = WorkflowExecutionModel::query()->firstOrFail();

        $this->assertSame(ExecutionStatus::Failed->value, $execution->status);
        $this->assertNull($execution->claim_token, 'The claim must be released so recovery can take over.');

        $steps = StepExecutionModel::query()->orderBy('position')->get();

        $this->assertSame(StepStatus::Completed->value, $this->row($steps, 0, 'step')->status);
        $this->assertSame(StepStatus::Failed->value, $this->row($steps, 1, 'step')->status);
        $this->assertSame(StepStatus::Pending->value, $this->row($steps, 2, 'step')->status);

        // The checkpointed first step's result survives for the retry.
        $this->assertSame('RES-1-SKU-1001', $execution->context['reservation_id'] ?? null);
    }

    public function test_the_queue_flag_dispatches_the_demo_instead_of_running_it(): void
    {
        Queue::fake();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:demo', ['--queue' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Queued as execution 1', $output);
        $this->assertStringContainsString('queue:work', $output);

        $execution = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Pending->value, $execution->status);
        $this->assertSame(DemoWorkflow::class, $execution->workflow);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );
    }

    public function test_queue_with_a_sync_connection_reports_an_inline_step_failure_gracefully(): void
    {
        // No Queue::fake(): the default sync connection runs the job inside
        // the dispatch call, so the step failure surfaces in the command.
        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:demo',
            ['--queue' => true, '--fail' => true],
        );

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('ran the job in this process', $output);
        $this->assertStringContainsString('laraflow:execute 1', $output);

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }
}
