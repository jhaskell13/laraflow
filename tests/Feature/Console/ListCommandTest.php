<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
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
        $this->assertStringContainsString('pending, running, completed, failed', $output);
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
