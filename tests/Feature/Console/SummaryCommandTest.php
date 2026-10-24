<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Date;
use Laraflow\Demo\DemoWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

class SummaryCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_an_empty_installation_suggests_the_demo(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('No executions found. Try: laraflow:demo', $output);
    }

    public function test_it_counts_every_status_with_a_share_of_the_total(): void
    {
        $this->callCommand('laraflow:demo');
        $this->callCommand('laraflow:demo', ['--fail' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::SUCCESS, $status);

        // Every case appears, so a status added later is reported
        // without this command being touched.
        foreach (ExecutionStatus::cases() as $case) {
            $this->assertStringContainsString($case->value, $output);
        }

        $this->assertStringContainsString('total', $output);
        $this->assertStringContainsString('50.0%', $output);
    }

    public function test_the_scope_options_narrow_the_counts(): void
    {
        $this->runner()->run(new DemoWorkflow, $this->input(), new ExecutionOptions('req-1', 'acme'));
        $this->runner()->run(new DemoWorkflow, $this->input(), new ExecutionOptions('req-2', 'globex'));

        ['output' => $output] = $this->callCommand('laraflow:summary', ['--tenant' => 'acme']);

        // One of one, not one of two: the share is of the scoped set.
        $this->assertStringContainsString('100.0%', $output);
        $this->assertStringNotContainsString('50.0%', $output);
    }

    public function test_a_filter_matching_nothing_says_so(): void
    {
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:summary',
            ['--tenant' => 'nobody'],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('No executions match that filter.', $output);
    }

    public function test_a_healthy_installation_needs_no_attention_and_exits_zero(): void
    {
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Nothing needs attention', $output);
    }

    public function test_due_retries_are_surfaced_and_exit_non_zero(): void
    {
        Date::setTestNow('2026-10-01 09:00:00');
        $this->callCommand('laraflow:demo');

        WorkflowExecutionModel::query()->update([
            'status' => ExecutionStatus::Retrying->value,
            'next_retry_at' => '2026-10-01 08:59:00',
            'finished_at' => null,
        ]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::FAILURE, $status, 'Work needing attention must be detectable by a scheduler.');
        $this->assertStringContainsString('1 retry is due now', $output);
        $this->assertStringContainsString('2026-10-01 08:59:00', $output);
        $this->assertStringContainsString('laraflow:recover', $output);
    }

    public function test_a_step_past_its_deadline_is_surfaced_and_exits_non_zero(): void
    {
        Date::setTestNow('2026-10-01 09:00:00');
        $this->callCommand('laraflow:demo');

        WorkflowExecutionModel::query()->update([
            'status' => ExecutionStatus::Running->value,
            'finished_at' => null,
        ]);

        StepExecutionModel::query()->where('position', 0)->update([
            'status' => StepStatus::Running->value,
            'timeout_at' => '2026-10-01 08:58:00',
        ]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('running step past its deadline', $output);
        $this->assertStringContainsString('ReserveInventory', $output);

        // The honest caveat travels with the warning.
        $this->assertStringContainsString('does not interrupt running PHP', $output);
    }

    public function test_a_step_inside_its_deadline_is_not_reported(): void
    {
        Date::setTestNow('2026-10-01 09:00:00');
        $this->callCommand('laraflow:demo');

        WorkflowExecutionModel::query()->update([
            'status' => ExecutionStatus::Running->value,
            'finished_at' => null,
        ]);

        StepExecutionModel::query()->where('position', 0)->update([
            'status' => StepStatus::Running->value,
            'timeout_at' => '2026-10-01 09:05:00',
        ]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Nothing needs attention', $output);
    }

    public function test_the_oldest_unfinished_execution_is_named(): void
    {
        Date::setTestNow('2026-10-01 09:00:00');
        $this->runner()->start(new DemoWorkflow, $this->input());

        Date::setTestNow('2026-10-01 10:00:00');
        $this->runner()->start(new DemoWorkflow, $this->input());

        ['output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertStringContainsString('Oldest unfinished execution: [1] DemoWorkflow', $output);
        $this->assertStringContainsString('2026-10-01 09:00:00', $output);
    }

    public function test_completed_work_is_not_called_unfinished(): void
    {
        $this->callCommand('laraflow:demo');

        ['output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertStringNotContainsString('Oldest unfinished execution', $output);
    }

    public function test_an_unparseable_time_is_rejected(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:summary',
            ['--since' => 'whenever'],
        );

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('must be a date or time', $output);
    }

    public function test_the_command_reads_nothing_through_the_eloquent_models(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Console/SummaryCommand.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Models\\', $source);
    }

    /**
     * @return array<string, mixed>
     */
    protected function input(): array
    {
        return ['sku' => 'SKU-1', 'quantity' => 1, 'fail' => false];
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
