<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Date;
use Laraflow\Demo\DemoWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
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

    // ------------------------------------------------------------------
    // Milestone 4: the command runs on the public query API
    // ------------------------------------------------------------------

    public function test_it_reports_how_much_of_the_matching_set_was_shown(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->callCommand('laraflow:demo');
        }

        ['output' => $output] = $this->callCommand('laraflow:list', ['--limit' => '2']);

        // A bounded page that does not say so looks like the whole truth.
        $this->assertStringContainsString('Showing 2 of 3 matching execution(s).', $output);
    }

    public function test_it_filters_by_workflow(): void
    {
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:list',
            ['--workflow' => [DemoWorkflow::class]],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('DemoWorkflow', $output);

        ['output' => $none] = $this->callCommand('laraflow:list', ['--workflow' => ['App\Nope']]);

        $this->assertStringContainsString('No executions match that filter.', $none);
    }

    public function test_it_filters_by_correlation_and_tenant(): void
    {
        $this->runner()->run(new DemoWorkflow, $this->input(), new ExecutionOptions('req-1', 'acme'));
        $this->runner()->run(new DemoWorkflow, $this->input(), new ExecutionOptions('req-2', 'globex'));

        ['output' => $byCorrelation] = $this->callCommand('laraflow:list', ['--correlation' => 'req-1']);
        $this->assertStringContainsString('Showing 1 of 1 matching execution(s).', $byCorrelation);

        ['output' => $byTenant] = $this->callCommand('laraflow:list', ['--tenant' => 'globex']);
        $this->assertStringContainsString('Showing 1 of 1 matching execution(s).', $byTenant);

        ['output' => $byBoth] = $this->callCommand(
            'laraflow:list',
            ['--correlation' => 'req-1', '--tenant' => 'globex'],
        );
        $this->assertStringContainsString('No executions match that filter.', $byBoth);
    }

    public function test_it_filters_by_a_creation_window(): void
    {
        Date::setTestNow('2026-10-01 09:00:00');
        $this->callCommand('laraflow:demo');

        Date::setTestNow('2026-10-01 11:00:00');
        $this->callCommand('laraflow:demo');

        ['output' => $output] = $this->callCommand('laraflow:list', ['--since' => '2026-10-01 10:00:00']);

        $this->assertStringContainsString('Showing 1 of 1 matching execution(s).', $output);
    }

    public function test_a_relative_time_is_accepted(): void
    {
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:list',
            ['--since' => '-1 hour'],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Showing 1 of 1', $output);
    }

    public function test_an_unparseable_time_is_rejected_with_guidance(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:list',
            ['--since' => 'last tuesday-ish'],
        );

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('must be a date or time', $output);
        $this->assertStringContainsString('-2 hours', $output);
    }

    public function test_the_oldest_option_reverses_the_order(): void
    {
        $this->callCommand('laraflow:demo');
        $this->callCommand('laraflow:demo');

        ['output' => $output] = $this->callCommand('laraflow:list', ['--limit' => '1', '--oldest' => true]);

        $this->assertStringContainsString('| 1  |', $output);
        $this->assertStringNotContainsString('| 2  |', $output);
    }

    public function test_a_bounded_page_prints_the_command_that_continues_it(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->callCommand('laraflow:demo');
        }

        ['output' => $output] = $this->callCommand('laraflow:list', ['--limit' => '2']);

        // The hint must carry the current filter forward, or following
        // it silently widens the query.
        $this->assertStringContainsString('laraflow:list --cursor=2', $output);
        $this->assertStringContainsString('--limit=2', $output);

        // And it must be copy-pasteable: Laravel's info component
        // appends a full stop, which would become part of --limit.
        $this->assertStringNotContainsString('--limit=2.', $output);
    }

    public function test_the_printed_cursor_fetches_exactly_the_next_page(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->callCommand('laraflow:demo');
        }

        ['output' => $second] = $this->callCommand(
            'laraflow:list',
            ['--limit' => '2', '--cursor' => '2'],
        );

        $this->assertStringContainsString('| 1  |', $second);
        $this->assertStringNotContainsString('| 3  |', $second);
        $this->assertStringNotContainsString('| 2  |', $second);
        $this->assertStringNotContainsString('Next page', $second, 'The last page offers no next page.');
    }

    public function test_a_malformed_cursor_is_refused_rather_than_guessed_at(): void
    {
        $this->callCommand('laraflow:demo');

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:list',
            ['--cursor' => 'page-two'],
        );

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('Malformed execution cursor', $output);
    }

    public function test_the_table_shows_the_single_finished_timestamp(): void
    {
        Date::setTestNow('2026-10-01 09:00:00');
        $this->callCommand('laraflow:demo');

        ['output' => $output] = $this->callCommand('laraflow:list');

        // Completed, failed, and cancelled all report through finished_at.
        $this->assertStringContainsString('2026-10-01 09:00:00', $output);
    }

    public function test_the_command_reads_nothing_through_the_eloquent_models(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Console/ListCommand.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString(
            'Models\\',
            $source,
            'laraflow:list must run on the public repository API, not the internal models.',
        );
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
