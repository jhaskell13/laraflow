<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Symfony\Component\Console\Command\Command as CommandStatus;

class ListCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_an_empty_table_suggests_the_demo(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('No executions found. Try: laraflow:demo', $output);
    }

    public function test_it_lists_executions_with_status_and_step_progress(): void
    {
        $this->callCommand('laraflow:demo');
        $this->callCommand('laraflow:demo', ['--fail' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('DemoWorkflow', $output);
        $this->assertStringContainsString('completed', $output);
        $this->assertStringContainsString('3/3', $output);
        $this->assertStringContainsString('failed', $output);
        $this->assertStringContainsString('1/3', $output);
        $this->assertStringContainsString('Simulated payment gateway timeout', $output);
    }

    public function test_the_status_filter_narrows_the_table(): void
    {
        $this->callCommand('laraflow:demo');
        $this->callCommand('laraflow:demo', ['--fail' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list', ['--status' => 'failed']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('1/3', $output);
        $this->assertStringNotContainsString('3/3', $output);
    }

    public function test_an_invalid_status_is_rejected_with_the_valid_options(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list', ['--status' => 'exploded']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('Unknown status [exploded]', $output);

        // Asserted case by case rather than as one joined string: a
        // prefix match would silently keep passing as cases are added.
        foreach (ExecutionStatus::cases() as $case) {
            $this->assertStringContainsString($case->value, $output);
        }
    }

    public function test_the_status_help_text_lists_every_status_that_exists(): void
    {
        ['output' => $output] = $this->callCommand('laraflow:list', ['--help' => true]);

        foreach (ExecutionStatus::cases() as $case) {
            $this->assertStringContainsString(
                $case->value,
                $output,
                "Help text omits the [{$case->value}] status.",
            );
        }
    }

    public function test_executions_can_be_listed_by_a_newly_added_status(): void
    {
        $this->callCommand('laraflow:demo');

        WorkflowExecutionModel::query()->update(['status' => ExecutionStatus::Retrying->value]);

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:list',
            ['--status' => 'retrying'],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('retrying', $output);
    }

    public function test_the_limit_option_bounds_the_table(): void
    {
        $this->callCommand('laraflow:demo');
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list', ['--limit' => '1']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('| 2  |', $output);
        $this->assertStringNotContainsString('| 1  |', $output);
    }
}
