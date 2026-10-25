<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command as CommandStatus;

class PruneCommandTest extends TestCase
{
    use RunsArtisanCommands;

    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);
    }

    // ------------------------------------------------------------------
    // Refusing to run unconfigured
    // ------------------------------------------------------------------

    public function test_it_refuses_when_no_bound_is_configured(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune');

        // A scheduled prune that silently does nothing is
        // indistinguishable from one that is working, right up until
        // someone looks at the size of the attempt table.
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('No retention bounds are configured', $output);
        $this->assertStringContainsString('prune_attempts_after_days', $output);
        $this->assertStringContainsString('--prune-attempts-after=30 --dry-run', $output);
    }

    public function test_it_refuses_unconfigured_even_for_a_dry_run(): void
    {
        ['status' => $status] = $this->callCommand('laraflow:prune', ['--dry-run' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
    }

    // ------------------------------------------------------------------
    // Dry run
    // ------------------------------------------------------------------

    public function test_a_dry_run_reports_without_removing_anything(): void
    {
        $this->aged(1, 200);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', [
            '--delete-after' => '90',
            '--dry-run' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('deleted', $output);
        $this->assertStringContainsString('1 execution(s) would be acted on', $output);
        $this->assertStringContainsString('Re-run without --dry-run', $output);

        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_a_dry_run_does_not_claim_an_attempt_row_count(): void
    {
        $this->aged(1, 200);

        ['output' => $output] = $this->callCommand('laraflow:prune', [
            '--prune-attempts-after' => '30',
            '--dry-run' => true,
        ]);

        $this->assertStringContainsString('row count unknown until the run', $output);
    }

    public function test_a_dry_run_is_not_logged(): void
    {
        $this->aged(1, 200);

        $this->callCommand('laraflow:prune', ['--delete-after' => '90', '--dry-run' => true]);

        $this->assertSame([], $this->pruneRecords());
    }

    // ------------------------------------------------------------------
    // Applying the policy
    // ------------------------------------------------------------------

    public function test_it_deletes_finished_work_past_the_bound(): void
    {
        $this->aged(1, 200);
        $this->aged(2, 10);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', [
            '--delete-after' => '90',
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Pruned 1 execution(s).', $output);

        $this->assertSame([2], WorkflowExecutionModel::query()->pluck('id')->all());
        $this->assertSame(0, StepExecutionModel::query()->where('workflow_execution_id', 1)->count());
    }

    public function test_it_prunes_attempt_history_and_reports_the_rows(): void
    {
        $this->aged(1, 200);

        ['output' => $output] = $this->callCommand('laraflow:prune', [
            '--prune-attempts-after' => '30',
        ]);

        $this->assertStringContainsString('attempts pruned', $output);
        $this->assertStringContainsString('attempt row(s)', $output);

        $this->assertSame(0, StepAttemptModel::query()->count());
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_it_scrubs_payloads_and_keeps_the_record(): void
    {
        $this->aged(1, 200);

        ['output' => $output] = $this->callCommand('laraflow:prune', ['--scrub-after' => '30']);

        $this->assertStringContainsString('payloads scrubbed', $output);

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertNull($row->input);
        $this->assertNotNull($row->payloads_scrubbed_at);
    }

    public function test_only_the_configured_tiers_appear_in_the_report(): void
    {
        $this->aged(1, 200);

        ['output' => $output] = $this->callCommand('laraflow:prune', ['--delete-after' => '90']);

        $this->assertStringContainsString('deleted', $output);

        // A tier with no bound is not a tier that did nothing; it is a
        // tier the operator never asked for.
        $this->assertStringNotContainsString('payloads scrubbed', $output);
        $this->assertStringNotContainsString('attempts pruned', $output);
    }

    public function test_nothing_in_range_says_so(): void
    {
        $this->aged(1, 10);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', [
            '--delete-after' => '90',
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Nothing to prune', $output);
    }

    public function test_a_completed_run_is_logged(): void
    {
        $this->aged(1, 200);

        $this->callCommand('laraflow:prune', ['--delete-after' => '90']);

        $records = $this->pruneRecords();

        // Deletions an operator cannot later account for are exactly
        // the ones they will want to account for.
        $this->assertCount(1, $records);
        $this->assertSame(1, $records[0]['context']['deleted']);
        $this->assertArrayHasKey('attempt_rows', $records[0]['context']);
    }

    public function test_a_run_that_did_nothing_is_not_logged(): void
    {
        $this->aged(1, 10);

        $this->callCommand('laraflow:prune', ['--delete-after' => '90']);

        $this->assertSame([], $this->pruneRecords());
    }

    // ------------------------------------------------------------------
    // Configuration and overrides
    // ------------------------------------------------------------------

    public function test_it_uses_the_configured_policy_when_no_option_is_given(): void
    {
        config(['laraflow.retention.delete_after_days' => 90]);
        $this->aged(1, 200);

        ['status' => $status] = $this->callCommand('laraflow:prune');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_an_option_overrides_the_configured_bound(): void
    {
        config(['laraflow.retention.delete_after_days' => 90]);
        $this->aged(1, 120);

        $this->callCommand('laraflow:prune', ['--delete-after' => '365']);

        // 120 days is past the configured 90 but inside the override.
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_a_non_numeric_option_is_rejected(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', [
            '--delete-after' => 'soon',
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('must be a number of days, got [soon]', $output);
    }

    public function test_an_invalid_bound_is_rejected_with_the_policy_s_reasoning(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', [
            '--delete-after' => '0',
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('at least 1 day', $output);
        $this->assertStringContainsString('finished seconds ago', $output);
    }

    public function test_the_limit_option_bounds_the_run(): void
    {
        $this->aged(1, 200);
        $this->aged(2, 200);
        $this->aged(3, 200);

        $this->callCommand('laraflow:prune', ['--delete-after' => '90', '--limit' => '2']);

        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    // ------------------------------------------------------------------
    // Warnings
    // ------------------------------------------------------------------

    public function test_it_warns_about_a_policy_that_cannot_do_what_it_says(): void
    {
        $this->aged(1, 200);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', [
            '--scrub-after' => '90',
            '--delete-after' => '30',
            '--dry-run' => true,
        ]);

        // Valid, so it still runs — but almost certainly a typo.
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('scrubbing never runs', $output);
    }

    // ------------------------------------------------------------------
    // Scoping
    // ------------------------------------------------------------------

    public function test_it_can_be_scoped_to_one_workflow(): void
    {
        $this->aged(1, 200);
        $this->aged(2, 200);

        WorkflowExecutionModel::query()->whereKey(2)->update(['workflow' => 'App\Other']);

        $this->callCommand('laraflow:prune', [
            '--delete-after' => '90',
            '--workflow' => 'App\Other',
        ]);

        $this->assertSame([1], WorkflowExecutionModel::query()->pluck('id')->all());
    }

    public function test_it_can_be_scoped_to_one_tenant(): void
    {
        $this->aged(1, 200);
        $this->aged(2, 200);

        WorkflowExecutionModel::query()->whereKey(1)->update(['tenant_id' => 'acme']);

        $this->callCommand('laraflow:prune', [
            '--delete-after' => '90',
            '--tenant' => 'acme',
        ]);

        $this->assertSame([2], WorkflowExecutionModel::query()->pluck('id')->all());
    }

    // ------------------------------------------------------------------
    // The production guard
    // ------------------------------------------------------------------

    public function test_it_asks_before_deleting_in_production(): void
    {
        $this->aged(1, 200);
        $this->app['env'] = 'production';

        // The same guard migrate and db:wipe use. Declining leaves the
        // data alone and exits non-zero, so a scheduled run that lost
        // its --force cannot look like a successful prune.
        $this->artisan('laraflow:prune', ['--delete-after' => '90'])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_confirming_in_production_proceeds(): void
    {
        $this->aged(1, 200);
        $this->app['env'] = 'production';

        $this->artisan('laraflow:prune', ['--delete-after' => '90'])
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_it_proceeds_in_production_with_force(): void
    {
        $this->app['env'] = 'production';
        $this->aged(1, 200);

        ['status' => $status] = $this->callCommand('laraflow:prune', [
            '--delete-after' => '90',
            '--force' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_a_dry_run_in_production_needs_no_force(): void
    {
        $this->app['env'] = 'production';
        $this->aged(1, 200);

        ['status' => $status] = $this->callCommand('laraflow:prune', [
            '--delete-after' => '90',
            '--dry-run' => true,
        ]);

        // It changes nothing, so it has nothing to confirm.
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_the_command_touches_no_eloquent_model(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Console/PruneCommand.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Models\\', $source);
    }

    /**
     * A finished demo execution whose finish is $days in the past.
     */
    protected function aged(int $id, int $days): void
    {
        if (WorkflowExecutionModel::query()->find($id) === null) {
            $this->callCommand('laraflow:demo');
        }

        DB::table('laraflow_workflow_executions')
            ->where('id', $id)
            ->update(['finished_at' => now()->subDays($days)]);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    protected function pruneRecords(): array
    {
        return array_values(array_filter(
            $this->logger->records,
            fn (array $record): bool => str_contains($record['message'], 'pruned finished executions'),
        ));
    }
}
