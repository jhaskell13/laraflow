<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use Laraflow\Tests\TestCase;

/**
 * The schema freeze, enforced rather than promised.
 *
 * From Milestone 6 the shipped migrations are immutable: an
 * application that has run them must be able to reach any later version
 * by running *new* migrations, never by being told to drop its tables.
 * These assertions are the exact shape of the frozen schema, so editing
 * a shipped migration fails here with the reason, and a genuine change
 * can only be made the way an installed application can follow.
 *
 * Adding a column, index, or table in a *new* dated migration is the
 * supported path — and it will fail here too, on purpose. Update the
 * expectation in the same commit: it is the record of what the current
 * schema is, and reviewing its diff is how a schema change gets noticed.
 */
class SchemaFreezeTest extends TestCase
{
    /**
     * Every column of every Laraflow table, sorted.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'laraflow_workflow_executions' => [
            'available_at', 'cancel_reason', 'cancel_requested_at', 'cancelled_at',
            'claim_token', 'claimed_at', 'completed_at', 'context', 'correlation_id',
            'created_at', 'dispatched_at', 'duration_ms', 'error', 'error_class',
            'failed_at', 'finished_at', 'fruitless_claims', 'id', 'input', 'next_retry_at',
            'parent_execution_id', 'parent_step_execution_id',
            'payloads_scrubbed_at', 'started_at', 'status', 'tenant_id',
            'updated_at', 'version', 'workflow',
        ],
        'laraflow_step_executions' => [
            'attempts', 'completed_at', 'created_at', 'error', 'error_class',
            'failed_at', 'id', 'input', 'next_retry_at', 'output', 'position',
            'started_at', 'status', 'step', 'timeout_at', 'updated_at',
            'wait_signal', 'workflow_execution_id',
        ],
        'laraflow_step_attempts' => [
            'attempt', 'created_at', 'duration_ms', 'ended_at', 'error',
            'error_class', 'id', 'next_retry_at', 'started_at', 'status',
            'step_execution_id', 'updated_at', 'workflow_execution_id',
        ],
    ];

    /**
     * Every index of every Laraflow table, as sorted column lists. The
     * primary key appears as ['id']; engines name indexes differently,
     * so the columns are what is compared.
     *
     * @var array<string, list<list<string>>>
     */
    private const INDEXES = [
        'laraflow_workflow_executions' => [
            ['correlation_id'],
            ['created_at'],
            ['id'],
            ['parent_execution_id'],
            ['payloads_scrubbed_at', 'status', 'finished_at'],
            ['status', 'claimed_at'],
            ['status', 'finished_at'],
            ['status', 'next_retry_at'],
            ['tenant_id'],
            ['workflow', 'status'],
        ],
        'laraflow_step_executions' => [
            ['id'],
            ['workflow_execution_id', 'position'],
        ],
        'laraflow_step_attempts' => [
            ['id'],
            ['step_execution_id', 'attempt'],
            ['workflow_execution_id'],
        ],
    ];

    private const EXPLANATION = 'The shipped migrations are frozen: an installed application must be able '
        ."to reach this schema by running new migrations.\nIf this change is deliberate, add a new dated "
        .'migration for it and update this expectation in the same commit. If it is not, the edit belongs '
        .'in a new migration rather than in a shipped one. See docs/upgrading.md.';

    /**
     * SHA-256 of each shipped migration file. Column and index names
     * alone would let a type, a length, a default, or a cascade rule be
     * edited in place; the file hash lets nothing be.
     *
     * @var array<string, string>
     */
    private const MIGRATION_HASHES = [
        '2026_10_06_000000_create_laraflow_workflow_executions_table.php' => 'c866c055143e656d9435ac2e73d5b0a4b6d4434319336721e61da56c22da58c3',
        '2026_10_06_000001_create_laraflow_step_executions_table.php' => '3dd1872be87452f8e966fe433ff97e0d91ba2f1e78fa00ef59cdd3ed6e90560b',
        '2026_10_06_000002_create_laraflow_step_attempts_table.php' => 'b45743f5198690a6fb28aa623f59f818230fd2707d597a931bbd0b3776baaa17',
        '2026_10_28_000000_add_fruitless_claims_to_laraflow_workflow_executions.php' => 'cf71f282c465f00b97cef4231ee1d01b220d3104cbea4bead1b53b828a149827',
        '2026_10_28_000001_add_wait_signal_to_laraflow_step_executions.php' => '67736320011ef3d5dd9475ccab43508fcbf4514684c03e3de92f36d120210a85',
        '2026_10_28_000002_add_parent_links_to_laraflow_workflow_executions.php' => 'eb6f47d57df0250318dad185c8cd6d54e301ee89396ec25bd8772a3a1403fa18',
        '2026_10_28_000003_add_scrub_scan_index_to_laraflow_workflow_executions.php' => '250b1192065da97f03d14a4919437c9805cbd0429f6c9b2f3fc63f2c567462ac',
    ];

    public function test_the_frozen_tables_have_exactly_these_columns(): void
    {
        foreach (self::COLUMNS as $table => $expected) {
            $actual = collect(Schema::getColumns($table))
                ->pluck('name')
                ->sort()
                ->values()
                ->all();

            $this->assertSame($expected, $actual, "[{$table}] columns changed. ".self::EXPLANATION);
        }
    }

    public function test_the_frozen_tables_have_exactly_these_indexes(): void
    {
        foreach (self::INDEXES as $table => $expected) {
            $actual = collect(Schema::getIndexes($table))
                ->map(fn (array $index): array => $index['columns'])
                ->all();

            // Both sides through the same sort: engines report indexes
            // in their own order, and the literal above is written for
            // a reader rather than for PHP's array comparison rules.
            sort($expected);
            sort($actual);

            $this->assertEquals($expected, $actual, "[{$table}] indexes changed. ".self::EXPLANATION);
        }
    }

    public function test_the_shipped_migration_files_are_unchanged(): void
    {
        $directory = __DIR__.'/../../../database/migrations';
        $actual = [];

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $actual[basename($file)] = hash_file('sha256', $file);
        }

        ksort($actual);

        $this->assertSame(self::MIGRATION_HASHES, $actual, 'A shipped migration file changed. '.self::EXPLANATION
            .' A new migration is added to this list with its hash in the same commit.');
    }

    public function test_no_table_was_added_or_removed_without_notice(): void
    {
        foreach (array_keys(self::COLUMNS) as $table) {
            $this->assertTrue(Schema::hasTable($table), "[{$table}] is missing. ".self::EXPLANATION);
        }

        // A fourth Laraflow table is a schema change like any other, and
        // the queue tables this suite creates are Laravel's, not ours.
        $laraflow = collect(Schema::getTableListing())
            // SQL Server reports tables schema-qualified (dbo.foo).
            ->map(fn (string $table): string => (string) (strrchr($table, '.') ?: '.'.$table))
            ->map(fn (string $table): string => substr($table, 1))
            ->filter(fn (string $table): bool => str_starts_with($table, 'laraflow_'))
            ->sort()
            ->values()
            ->all();

        $expectedTables = array_keys(self::COLUMNS);
        sort($expectedTables);

        $this->assertSame($expectedTables, $laraflow, 'A Laraflow table was added or removed. '.self::EXPLANATION);
    }
}
