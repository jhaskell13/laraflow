<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Demo\DemoWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

class ShowCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_it_shows_the_correlation_and_tenant_identifiers(): void
    {
        $this->app->make(WorkflowRunner::class)->run(
            new DemoWorkflow,
            ['sku' => 'SKU-1', 'quantity' => 1, 'fail' => false],
            new ExecutionOptions('req-abc123', 'acme'),
        );

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:show', ['execution' => '1']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Correlation', $output);
        $this->assertStringContainsString('req-abc123', $output);
        $this->assertStringContainsString('Tenant', $output);
        $this->assertStringContainsString('acme', $output);
    }

    public function test_identifier_rows_are_omitted_when_none_were_supplied(): void
    {
        $this->callCommand('laraflow:demo');

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => '1']);

        // An empty row for an absent identifier is noise on a view an
        // operator reads under pressure.
        $this->assertStringNotContainsString('Correlation', $output);
        $this->assertStringNotContainsString('Tenant', $output);
    }

    public function test_the_attempt_history_covers_every_step_of_the_execution(): void
    {
        $this->callCommand('laraflow:demo', ['--fail' => true]);
        $this->callCommand('laraflow:execute', ['execution' => '1']);

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:show',
            ['execution' => '1', '--attempts' => true],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);

        // One query now serves all three steps; the rows must still
        // name the right step, including the failed-then-retried one.
        $this->assertStringContainsString('ReserveInventory', $output);
        $this->assertStringContainsString('ChargePayment', $output);
        $this->assertStringContainsString('SendReceipt', $output);
        $this->assertStringContainsString('failed', $output);
        $this->assertStringContainsString('completed', $output);
    }

    public function test_it_shows_a_completed_execution_in_full(): void
    {
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:show', ['execution' => '1']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString(DemoWorkflow::class, $output);
        $this->assertStringContainsString('completed', $output);
        $this->assertStringContainsString('ReserveInventory', $output);
        $this->assertStringContainsString('ChargePayment', $output);
        $this->assertStringContainsString('SendReceipt', $output);
        $this->assertStringContainsString('reservation_id', $output);
    }

    public function test_it_shows_the_error_of_a_failed_execution(): void
    {
        $this->callCommand('laraflow:demo', ['--fail' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:show', ['execution' => '1']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('failed', $output);
        $this->assertStringContainsString('Simulated payment gateway timeout', $output);
    }

    public function test_it_surfaces_an_active_claim(): void
    {
        $repository = $this->app->make(ExecutionRepository::class);
        $execution = $repository->create(DemoWorkflow::class, steps: (new DemoWorkflow)->steps());
        $repository->claim($execution->id, 'some-worker', 300);

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:show',
            ['execution' => (string) $execution->id],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('a worker owns or owned this execution', $output);
    }

    public function test_an_unknown_execution_id_fails_with_a_clear_message(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:show', ['execution' => '42']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('was not found', $output);
    }
}
