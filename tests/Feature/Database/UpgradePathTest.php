<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Database;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;

/**
 * What the schema freeze promises an installed application: that a
 * later version is reachable by running new migrations, with the data
 * already in the tables still there afterwards — and that the step can
 * be undone the same way.
 *
 * The future migration is written at run time rather than committed,
 * because the point is the mechanism, not any particular column. When
 * a real schema change does land, it arrives as a dated migration and
 * this harness is what it is tested through.
 */
class UpgradePathTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && File::isDirectory($this->path)) {
            // Rolled back explicitly, not left to the test transaction:
            // this is DDL, and MySQL and MariaDB commit the open
            // transaction on DDL, so the added column would survive
            // into every later test — including the freeze assertions.
            if (Schema::hasColumn('laraflow_workflow_executions', 'priority')) {
                $this->command('migrate:rollback', [
                    '--path' => $this->path,
                    '--realpath' => true,
                    '--force' => true,
                ])->run();
            }

            File::deleteDirectory($this->path);
        }

        parent::tearDown();
    }

    public function test_an_installed_database_takes_a_new_migration_and_keeps_its_data(): void
    {
        // An application that has been running for a while: the frozen
        // schema, with work in it.
        $execution = $this->app->make(ExecutionRepository::class)->create(
            workflow: 'App\\Workflows\\SyncCustomer',
            input: ['customer_id' => 42],
            steps: ['App\\Steps\\LoadCustomer'],
        );

        $this->assertFalse(Schema::hasColumn('laraflow_workflow_executions', 'priority'));

        $this->migrateFuture();

        $this->assertTrue(
            Schema::hasColumn('laraflow_workflow_executions', 'priority'),
            'A new dated migration must apply to an already-installed database.',
        );

        $stored = WorkflowExecutionModel::query()->findOrFail($execution->id);

        $this->assertSame('App\\Workflows\\SyncCustomer', $stored->workflow);
        $this->assertSame(['customer_id' => 42], $stored->input);
        $this->assertSame(ExecutionStatus::Pending->value, $stored->status);
        // getAttribute(), not ->priority: the column is added by the
        // migration under test, so the model cannot declare it.
        $this->assertNull($stored->getAttribute('priority'), 'An added column must be readable on rows that predate it.');

        // And the package keeps working on the upgraded schema.
        $found = $this->app->make(ExecutionRepository::class)->find($execution->id);
        $this->assertSame($execution->id, $found->id);
    }

    public function test_the_new_migration_rolls_back_without_touching_the_frozen_tables(): void
    {
        $execution = $this->app->make(ExecutionRepository::class)->create(
            workflow: 'App\\Workflows\\SyncCustomer',
            input: ['customer_id' => 42],
            steps: ['App\\Steps\\LoadCustomer'],
        );

        $this->migrateFuture();

        $this->command('migrate:rollback', ['--path' => $this->path, '--realpath' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('laraflow_workflow_executions', 'priority'));

        // Rolling back the upgrade is not the same as losing the
        // install: the frozen tables and their rows are still here.
        $this->assertTrue(Schema::hasTable('laraflow_workflow_executions'));
        $this->assertTrue(Schema::hasTable('laraflow_step_executions'));
        $this->assertTrue(Schema::hasTable('laraflow_step_attempts'));

        $stored = WorkflowExecutionModel::query()->findOrFail($execution->id);
        $this->assertSame(['customer_id' => 42], $stored->input);
    }

    public function test_the_first_real_post_freeze_migration_upgrades_an_installed_database(): void
    {
        // The Milestone 7 migration, exercised the way an installed
        // application meets it: rows written under the frozen schema,
        // then the new migration applied — simulated by rolling it back
        // first, since the harness installs every migration up front.
        $execution = $this->app->make(ExecutionRepository::class)->create(
            workflow: 'App\\Workflows\\SyncCustomer',
            input: ['customer_id' => 42],
            steps: ['App\\Steps\\LoadCustomer'],
        );

        $this->command('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('laraflow_workflow_executions', 'fruitless_claims'));
        $this->assertTrue(Schema::hasTable('laraflow_step_attempts'), 'One step back is one migration, not the batch.');
        $this->assertSame(1, WorkflowExecutionModel::query()->count(), 'Rolling the upgrade back keeps the install.');

        // Migrate back up before the test ends: MySQL and MariaDB commit
        // on DDL, so the rest of the suite would otherwise run without
        // the column regardless of engine.
        $this->command('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('laraflow_workflow_executions', 'fruitless_claims'));

        // A row that predates the column has made no fruitless claims,
        // and the package reads it that way without a coalesce.
        $this->assertSame(0, WorkflowExecutionModel::query()->findOrFail($execution->id)->fruitless_claims);
        $this->assertSame(0, $this->app->make(ExecutionRepository::class)->find($execution->id)->fruitlessClaims);

        // And the upgraded row takes part in the new behaviour.
        $claimed = $this->app->make(ExecutionRepository::class)->claim($execution->id, 'worker', 300);
        $this->assertSame(1, $claimed->fruitlessClaims);
    }

    public function test_running_migrate_again_changes_nothing(): void
    {
        // The frozen migrations are already applied by the test harness;
        // an application pulling a release with no schema change must
        // get a no-op rather than an error.
        $this->command('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('laraflow_workflow_executions'));
        $this->assertFalse(Schema::hasColumn('laraflow_workflow_executions', 'priority'));
    }

    /**
     * Write and run a dated migration that lands after the frozen ones.
     */
    private function migrateFuture(): void
    {
        $this->path = sys_get_temp_dir().'/laraflow-upgrade-'.bin2hex(random_bytes(6));

        File::makeDirectory($this->path, recursive: true);

        File::put($this->path.'/2099_01_01_000000_add_priority_to_laraflow_workflow_executions.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
                        $table->unsignedInteger('priority')->nullable();
                    });
                }

                public function down(): void
                {
                    Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
                        $table->dropColumn('priority');
                    });
                }
            };
            PHP);

        $this->command('migrate', ['--path' => $this->path, '--realpath' => true, '--force' => true])
            ->assertSuccessful();
    }
}
