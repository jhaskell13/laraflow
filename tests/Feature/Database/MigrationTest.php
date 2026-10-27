<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use Laraflow\Tests\TestCase;

class MigrationTest extends TestCase
{
    public function test_migrations_are_loaded_by_the_service_provider(): void
    {
        $paths = app('migrator')->paths();

        $this->assertNotEmpty(array_filter(
            $paths,
            fn (string $path) => str_ends_with(
                str_replace('\\', '/', realpath($path) ?: $path),
                'database/migrations'
            )
        ));
    }

    public function test_laraflow_tables_exist(): void
    {
        $this->assertTrue(
            Schema::hasTable('laraflow_workflow_executions')
        );

        $this->assertTrue(
            Schema::hasTable('laraflow_step_executions')
        );

        $this->assertTrue(
            Schema::hasTable('laraflow_step_attempts')
        );
    }

    public function test_workflow_executions_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'laraflow_workflow_executions',
                [
                    'id',
                    'workflow',
                    'status',
                    'input',
                    'context',
                    'error',
                    'error_class',
                    'claim_token',
                    'claimed_at',
                    'started_at',
                    'completed_at',
                    'failed_at',
                    'next_retry_at',
                    'cancel_requested_at',
                    'cancelled_at',
                    'cancel_reason',
                    'correlation_id',
                    'tenant_id',
                    'dispatched_at',
                    'available_at',
                    'finished_at',
                    'duration_ms',
                    'payloads_scrubbed_at',
                    'created_at',
                    'updated_at',
                ]
            )
        );
    }

    public function test_step_executions_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'laraflow_step_executions',
                [
                    'id',
                    'workflow_execution_id',
                    'step',
                    'position',
                    'status',
                    'attempts',
                    'input',
                    'output',
                    'error',
                    'error_class',
                    'started_at',
                    'completed_at',
                    'failed_at',
                    'timeout_at',
                    'next_retry_at',
                    'created_at',
                    'updated_at',
                ]
            )
        );
    }

    public function test_step_attempts_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'laraflow_step_attempts',
                [
                    'id',
                    'workflow_execution_id',
                    'step_execution_id',
                    'attempt',
                    'status',
                    'error',
                    'error_class',
                    'started_at',
                    'ended_at',
                    'duration_ms',
                    'next_retry_at',
                    'created_at',
                    'updated_at',
                ]
            )
        );
    }

    public function test_executions_are_indexed_for_both_recovery_scans(): void
    {
        $indexed = array_map(
            fn (array $index): array => $index['columns'],
            Schema::getIndexes('laraflow_workflow_executions'),
        );

        // Stale-claim recovery (Milestone 2) and the due-retry scan
        // (Milestone 3) must each be an indexed single-table query.
        $this->assertContains(['status', 'claimed_at'], $indexed);
        $this->assertContains(['status', 'next_retry_at'], $indexed);
    }

    public function test_every_listing_and_retention_filter_has_an_index(): void
    {
        $indexed = array_map(
            fn (array $index): array => $index['columns'],
            Schema::getIndexes('laraflow_workflow_executions'),
        );

        // Milestone 4 promises bounded listing queries. An unindexed
        // filter on a high-volume table is how observability tooling
        // takes an application down, so each one is pinned here.
        $this->assertContains(['correlation_id'], $indexed, 'Correlating an execution must be indexed.');
        $this->assertContains(['tenant_id'], $indexed, 'Listing a tenant must be indexed.');
        $this->assertContains(['created_at'], $indexed, 'Paginating a creation window must be indexed.');
        $this->assertContains(['workflow', 'status'], $indexed, 'Filtering a workflow by status must be indexed.');
        $this->assertContains(['status', 'finished_at'], $indexed, 'The retention scan must be indexed.');
    }

    public function test_one_attempt_number_per_step_is_enforced_by_the_database(): void
    {
        $indexed = collect(Schema::getIndexes('laraflow_step_attempts'))
            ->firstWhere('columns', ['step_execution_id', 'attempt']);

        $this->assertNotNull($indexed, 'Expected a (step_execution_id, attempt) index.');
        $this->assertTrue($indexed['unique'], 'An attempt number must be unique per step.');
    }

    public function test_rolling_back_drops_the_attempt_history_table(): void
    {
        $this->command('migrate:rollback')->assertSuccessful();

        $this->assertFalse(Schema::hasTable('laraflow_step_attempts'));

        // Migrate back up before the test ends. SQLite and PostgreSQL
        // would roll the drop back with the test transaction, but MySQL
        // and MariaDB commit on DDL, and the rest of the suite needs
        // the tables regardless of which engine is running.
        $this->command('migrate')->assertSuccessful();
    }
}
