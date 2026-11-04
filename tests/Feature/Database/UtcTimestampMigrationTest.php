<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\WorkflowExecutionModel;

/**
 * The 2026_11_04_000002 migration on data an installed application
 * already has: rows written in the old convention — `app.timezone`
 * wall-clock — come out as UTC, a UTC application's rows are left
 * alone, and the rollback is the exact reverse.
 */
class UtcTimestampMigrationTest extends TestCase
{
    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_11_04_000002_store_laraflow_timestamps_in_utc.php';

    protected function tearDown(): void
    {
        // On MySQL and MariaDB the column change is DDL, which commits
        // the test transaction: leave the schema migrated and the table
        // empty, as UpgradePathTest does.
        config()->set('app.timezone', 'UTC');
        $this->migrate('up');
        WorkflowExecutionModel::query()->delete();

        parent::tearDown();
    }

    public function test_a_non_utc_applications_rows_are_converted_to_utc_and_back(): void
    {
        $id = $this->rowWrittenInTheOldConvention([
            // 01:30 EDT, the first occurrence of the repeated hour.
            'next_retry_at' => '2026-11-01 01:30:00',
            // EST, after the change.
            'finished_at' => '2026-11-01 03:00:00',
            // EDT, months before.
            'created_at' => '2026-07-04 12:00:00',
        ]);

        config()->set('app.timezone', 'America/New_York');

        $this->migrate('up');

        $this->assertSame([
            'next_retry_at' => '2026-11-01 05:30:00',
            'finished_at' => '2026-11-01 08:00:00',
            'created_at' => '2026-07-04 16:00:00',
            'claimed_at' => null,
        ], $this->stored($id), 'Each value read in New York, written in UTC; null stays null.');

        $this->migrate('down');

        $this->assertSame([
            'next_retry_at' => '2026-11-01 01:30:00',
            'finished_at' => '2026-11-01 03:00:00',
            'created_at' => '2026-07-04 12:00:00',
            'claimed_at' => null,
        ], $this->stored($id), 'The rollback restores the old convention exactly.');

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertSame('timestamp', Schema::getColumnType('laraflow_workflow_executions', 'next_retry_at'));
        }
    }

    public function test_a_utc_applications_rows_are_left_alone(): void
    {
        $id = $this->rowWrittenInTheOldConvention(['next_retry_at' => '2026-11-01 01:30:00']);

        config()->set('app.timezone', 'UTC');

        $this->migrate('up');

        $this->assertSame('2026-11-01 01:30:00', $this->stored($id)['next_retry_at']);

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertSame('datetime', Schema::getColumnType('laraflow_workflow_executions', 'next_retry_at'), 'No 2038 overflow, no session-zone conversion.');
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function rowWrittenInTheOldConvention(array $values): int
    {
        $id = $this->app->make(ExecutionRepository::class)->create('App\\Workflows\\Sync')->id;

        DB::table('laraflow_workflow_executions')->where('id', $id)->update($values + ['claimed_at' => null]);

        return $id;
    }

    /**
     * @return array<string, string|null>
     */
    private function stored(int $id): array
    {
        $row = (array) DB::table('laraflow_workflow_executions')->where('id', $id)->first();
        $values = [];

        foreach (['next_retry_at', 'finished_at', 'created_at', 'claimed_at'] as $column) {
            // SQL Server returns fractions; the columns hold seconds.
            $values[$column] = $row[$column] === null ? null : substr((string) $row[$column], 0, 19);
        }

        return $values;
    }

    /**
     * Run the shipped migration's up() or down() directly: the harness
     * has already recorded it as run, so `migrate` would skip it.
     */
    private function migrate(string $direction): void
    {
        $migration = require self::MIGRATION;
        $this->assertInstanceOf(Migration::class, $migration);

        $migration->{$direction}();
    }
}
