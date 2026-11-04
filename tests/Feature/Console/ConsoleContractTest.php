<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\FilterInput;
use Laraflow\Support\Handoff;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\LeakyStep;
use Laraflow\Tests\Support\NightlyWorkflow;
use Laraflow\Tests\Support\OneStepWorkflow;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\TokenRedactor;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\Tenancy\TenantCeilings;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * The console's contract at its edges (Milestone 10 audit): --json
 * survives the unexpected, messages are redacted, exit codes mean what
 * the command did, numbers are digits, filters read as the dashboard
 * reads them, and the generators live in Laraflow's own namespace.
 */
class ConsoleContractTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_an_unexpected_exception_under_json_is_one_redacted_error_document(): void
    {
        $this->useTokenRedactor();

        $this->app->instance(ExecutionRepository::class, new class($this->app->make(ClockInterface::class), $this->app->make(TenantCeilings::class)) extends EloquentExecutionRepository
        {
            public function count(\Laraflow\Workflow\ExecutionFilter $filter): int
            {
                throw new RuntimeException('database unreachable with tok_SECRET42');
            }
        });

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list', ['--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertSame(1, substr_count(trim($output), "\n") + 1, 'One line: no trace after the document.');

        $document = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['command', 'ok', 'error', 'exception'], array_keys($document));
        $this->assertSame('laraflow:list', $document['command']);
        $this->assertFalse($document['ok']);
        $this->assertSame('database unreachable with [redacted]', $document['error']);
        $this->assertSame(RuntimeException::class, $document['exception']);

        // The human form is unchanged: the exception propagates.
        $this->expectException(RuntimeException::class);
        $this->callCommand('laraflow:list');
    }

    public function test_a_failed_run_reports_the_redacted_message(): void
    {
        $this->useTokenRedactor();
        PolicyWorkflow::$steps = [StepOne::class, LeakyStep::class];

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', ['workflow' => PolicyWorkflow::class, '--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('[redacted]', $document['error']);
        $this->assertStringNotContainsString('tok_SENTINEL123', $output);
    }

    public function test_handoff_outcomes_are_snake_case(): void
    {
        $this->assertSame('push_failed', Handoff::PUSH_FAILED);
        $this->assertSame('step_failed', Handoff::STEP_FAILED);
    }

    public function test_numeric_options_accept_digits_only(): void
    {
        foreach (['1.5', '1e2', '-3', ' 2x'] as $value) {
            ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list', ['--limit' => $value]);
            $this->assertSame(CommandStatus::FAILURE, $status, $value);
            $this->assertStringContainsString('must be a positive integer', $output);

            ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', ['--prune-attempts-after' => $value, '--dry-run' => true]);
            $this->assertSame(CommandStatus::FAILURE, $status, $value);
            $this->assertStringContainsString('must be a number of days', $output);
        }

        $this->assertSame(CommandStatus::SUCCESS, $this->callCommand('laraflow:list', ['--limit' => '5'])['status']);
        $this->assertSame(CommandStatus::SUCCESS, $this->callCommand('laraflow:list', ['--limit' => 5])['status'], 'An int from Artisan::call() is a number too.');
    }

    public function test_prune_under_json_in_production_without_force_is_refused(): void
    {
        $this->app['env'] = 'production';

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', ['--prune-attempts-after' => '30', '--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['ok']);
        $this->assertStringContainsString('--force', $document['error']);

        ['status' => $status] = $this->callCommand('laraflow:prune', ['--prune-attempts-after' => '30', '--json' => true, '--force' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
    }

    public function test_an_empty_window_is_named_as_the_console_spells_it(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list', ['--since' => '2026-11-03', '--until' => '2026-11-01']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('--since must be earlier than --until', $output);
        $this->assertStringNotContainsString('createdAfter', $output);
    }

    public function test_filters_split_commas_strip_a_leading_backslash_and_cap_values_as_the_dashboard_does(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $runner->start(new OneStepWorkflow, [], new ExecutionOptions(tenantId: 'acme'));
        $runner->cancel($runner->start(new OneStepWorkflow)->id);

        ['output' => $output] = $this->callCommand('laraflow:list', ['--status' => ['pending,cancelled'], '--json' => true]);
        $this->assertSame(2, json_decode($output, true, 512, JSON_THROW_ON_ERROR)['total'], 'A comma list is two statuses.');

        ['output' => $output] = $this->callCommand('laraflow:list', ['--workflow' => ['\\'.OneStepWorkflow::class], '--json' => true]);
        $this->assertSame(2, json_decode($output, true, 512, JSON_THROW_ON_ERROR)['total'], 'A leading backslash names the same class.');

        $many = implode(',', array_map(fn (int $i): string => "App\\W{$i}", range(1, FilterInput::MAX_VALUES + 1)));
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:list', ['--workflow' => [$many]]);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('at most 20 values', $output);

        // The console refuses an unknown status; the dashboard drops it.
        ['status' => $status] = $this->callCommand('laraflow:list', ['--status' => ['pending,nope']]);
        $this->assertSame(CommandStatus::FAILURE, $status);
    }

    public function test_schedules_actions_exit_on_their_own_result_and_name_themselves(): void
    {
        $this->app->instance(ClockInterface::class, $clock = new FixedClock('2026-11-03 14:30:00'));
        config()->set('laraflow.schedules.workflows', [NightlyWorkflow::class]);
        $this->callCommand('laraflow:schedule');

        // Unlisted: the row is orphaned, so the listing needs attention.
        config()->set('laraflow.schedules.workflows', []);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--json' => true]);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['ok']);
        $this->assertSame('needs_attention', $document['reason']);
        $this->assertArrayNotHasKey('error', $document);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--pause' => NightlyWorkflow::class, '--json' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status, 'The pause landed; the orphan elsewhere is not its exit code.');
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($document['ok']);
        $this->assertSame('pause', $document['action']['name']);
        $this->assertSame(NightlyWorkflow::class, $document['action']['workflow']);
        $this->assertTrue($document['needs_attention']);

        // --forget asks in production; a machine must say --force.
        $this->app['env'] = 'production';
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--forget' => NightlyWorkflow::class, '--json' => true]);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('--force', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['error']);
        $this->assertNotNull($this->app->make(ScheduleRepository::class)->find(NightlyWorkflow::class));

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--forget' => NightlyWorkflow::class, '--json' => true, '--force' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertSame('forget', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['action']['name']);
        $this->assertNull($this->app->make(ScheduleRepository::class)->find(NightlyWorkflow::class));
    }

    public function test_the_generators_live_under_laraflow_and_the_old_names_warn(): void
    {
        $suffix = 'Renamed'.bin2hex(random_bytes(3));

        ['status' => $status, 'output' => $output] = $this->callCommand('make:workflow', ['name' => "Old{$suffix}"]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('[make:workflow] is deprecated', $output);
        $this->assertStringContainsString('laraflow:make-workflow', $output);

        ['output' => $output] = $this->callCommand('laraflow:make-workflow', ['name' => "New{$suffix}"]);
        $this->assertStringNotContainsString('deprecated', $output);

        // The old name reads the replacement's options, not just its
        // argument: --conditional reaches laraflow:make-step and picks
        // the conditional stub.
        ['status' => $status, 'output' => $output] = $this->callCommand('make:step', ['name' => "Old{$suffix}Step", '--conditional' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('[make:step] is deprecated', $output);
        $this->assertStringContainsString('ConditionalStep', (string) file_get_contents($this->app->path("Workflows/Steps/Old{$suffix}Step.php")));

        // Listed nowhere: not as commands of their own, and not beside
        // the commands that replaced them, which is how a Symfony alias
        // would print.
        Artisan::call('list', ['--raw' => true]);
        $this->assertStringNotContainsString('make:workflow', Artisan::output());
        Artisan::call('list');
        $this->assertStringNotContainsString('[make:', Artisan::output());

        foreach (['make:workflow', 'make:step', 'make:connector'] as $deprecated) {
            $this->assertTrue(Artisan::all()[$deprecated]->isHidden(), "{$deprecated} should be hidden");
        }

        @unlink($this->app->path("Workflows/Old{$suffix}.php"));
        @unlink($this->app->path("Workflows/New{$suffix}.php"));
        @unlink($this->app->path("Workflows/Steps/Old{$suffix}Step.php"));
    }

    protected function useTokenRedactor(): void
    {
        config(['laraflow.errors.redactor' => TokenRedactor::class]);
        $this->app->forgetInstance(ErrorRedactor::class);
    }
}
