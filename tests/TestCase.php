<?php

declare(strict_types=1);

namespace Laraflow\Tests;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Tests\Support\FixtureState;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase {
        beginDatabaseTransaction as traitBeginDatabaseTransaction;
    }

    /**
     * Testbench declares this `Application|null` because it is null
     * before the application is created. By the time any test method
     * runs it never is, and treating it as nullable would put a `?->`
     * or an assertion in front of several hundred ordinary container
     * calls without making one of them safer.
     *
     * Narrowed here rather than in a PHPStan stub so the claim lives
     * with the suite that relies on it.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture steps keep static state (run counters, failure switches);
        // resetting centrally means no test class can forget and leak state
        // into another under randomized ordering.
        FixtureState::reset();

        if (Env::get('LARAFLOW_TEST_LOG_SQL')) {
            $this->logDatabaseTraffic();
        }
    }

    /**
     * Reset every auto-increment before the test's transaction begins.
     *
     * Rolling a transaction back does not rewind id generators on
     * PostgreSQL (sequences), MySQL and MariaDB (in-memory counters),
     * or SQL Server (identity) — only SQLite starts from max(rowid)+1
     * of an empty table. Without this, the first row each test creates
     * has whatever id the process has counted up to, and a test cannot
     * say anything about the ids it will be given. Done here rather
     * than in setUp() because on MySQL and MariaDB these statements are
     * DDL, and DDL inside the test's transaction commits it.
     */
    protected function beginDatabaseTransaction(): void
    {
        $this->createQueueTables();

        $tables = [
            'laraflow_workflow_executions',
            'laraflow_step_executions',
            'laraflow_step_attempts',
            'jobs',
            'failed_jobs',
        ];

        $connection = $this->app['db']->connection();

        foreach ($tables as $table) {
            match ($connection->getDriverName()) {
                'pgsql' => $connection->statement("ALTER SEQUENCE {$table}_id_seq RESTART WITH 1"),
                'mysql', 'mariadb' => $connection->statement("ALTER TABLE {$table} AUTO_INCREMENT = 1"),
                'sqlsrv' => $connection->statement("DBCC CHECKIDENT ('{$table}', RESEED, 0)"),
                default => true,
            };
        }

        $this->traitBeginDatabaseTransaction();
    }

    /**
     * Trace every query and transaction event to STDERR, for debugging
     * an engine this machine cannot run. Enabled by LARAFLOW_TEST_LOG_SQL.
     */
    protected function logDatabaseTraffic(): void
    {
        $out = static fn (string $line) => fwrite(STDERR, '[sql] '.$line."\n");

        $this->app['db']->listen(fn ($query) => $out($query->sql));

        $events = $this->app['events'];
        $events->listen(\Illuminate\Database\Events\TransactionBeginning::class, fn () => $out('BEGIN (level up)'));
        $events->listen(\Illuminate\Database\Events\TransactionCommitted::class, fn () => $out('COMMIT'));
        $events->listen(\Illuminate\Database\Events\TransactionRolledBack::class, fn () => $out('ROLLBACK'));
    }

    /**
     * The step at a position, asserted to exist.
     *
     * Reading `steps[0]->status` directly takes a property off a row
     * that may not be there; when it is not, the test dies with a PHP
     * error about null instead of saying which step was missing. Going
     * through here turns that into an ordinary assertion failure.
     */
    protected function stepAt(WorkflowExecutionModel $execution, int $position): StepExecutionModel
    {
        $step = $execution->steps[$position] ?? null;

        $this->assertInstanceOf(
            StepExecutionModel::class,
            $step,
            "Expected a step at position {$position}; the execution has {$execution->steps->count()}.",
        );

        return $step;
    }

    /**
     * `artisan()` for the cases that chain expectations onto it.
     *
     * It is typed `PendingCommand|int` because it returns the exit code
     * once the command has already been run; before that it returns the
     * pending command that `assertSuccessful()` and friends live on.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pending, "[{$command}] has already run.");

        return $pending;
    }

    /**
     * The row at an index of a collection, asserted to exist. The same
     * reason as step(), for the collections that are not an execution's
     * steps — attempt history, query results.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @return TModel
     */
    protected function row(Collection $rows, int $index, string $what = 'row'): Model
    {
        $row = $rows[$index] ?? null;

        $this->assertNotNull($row, "Expected a {$what} at index {$index}; the collection has {$rows->count()}.");

        return $row;
    }

    /**
     * Laravel's queue tables, for the tests that run a real database
     * queue. Created from beginDatabaseTransaction(), before the
     * transaction starts — not from a queue test's setUp(), and not
     * from afterRefreshingDatabase(), which despite its name runs
     * after the transaction has begun. MySQL and MariaDB commit the
     * open transaction on any DDL, so a CREATE TABLE inside it breaks
     * the rollback isolation every later assertion relies on. SQLite
     * only tolerated it because its DDL rolls back.
     */
    protected function createQueueTables(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaraflowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', static::databaseConnection());
    }

    /**
     * The connection the whole suite runs against, driver chosen by the
     * environment so CI can prove the engines the compatibility policy
     * names rather than only SQLite.
     *
     *   LARAFLOW_TEST_DB_DRIVER      sqlite (default), mysql, mariadb,
     *                                pgsql, sqlsrv — or any driver the
     *                                installed Laravel resolves.
     *   LARAFLOW_TEST_DB_DATABASE    database name; for sqlite, a file
     *                                path or :memory: (default).
     *   LARAFLOW_TEST_DB_HOST        default 127.0.0.1
     *   LARAFLOW_TEST_DB_PORT        default per driver
     *   LARAFLOW_TEST_DB_USERNAME    default laraflow
     *   LARAFLOW_TEST_DB_PASSWORD    default secret
     *
     * @return array<string, mixed>
     */
    protected static function databaseConnection(): array
    {
        $driver = (string) Env::get('LARAFLOW_TEST_DB_DRIVER', 'sqlite');

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => (string) Env::get('LARAFLOW_TEST_DB_DATABASE', ':memory:'),
                'prefix' => '',
                // The schema has always declared cascading foreign keys, but
                // SQLite only enforces them when asked — without this, tests
                // asserting on cascade behaviour pass vacuously.
                'foreign_key_constraints' => true,
            ];
        }

        $connection = [
            'driver' => $driver,
            'host' => (string) Env::get('LARAFLOW_TEST_DB_HOST', '127.0.0.1'),
            'port' => (int) Env::get('LARAFLOW_TEST_DB_PORT', match ($driver) {
                'pgsql' => 5432,
                'sqlsrv' => 1433,
                default => 3306,
            }),
            'database' => (string) Env::get('LARAFLOW_TEST_DB_DATABASE', 'laraflow_test'),
            'username' => (string) Env::get('LARAFLOW_TEST_DB_USERNAME', 'laraflow'),
            'password' => (string) Env::get('LARAFLOW_TEST_DB_PASSWORD', 'secret'),
            'prefix' => '',
        ];

        return $connection + match ($driver) {
            'mysql', 'mariadb' => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ],
            'pgsql' => [
                'charset' => 'utf8',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'sqlsrv' => [
                // ODBC Driver 18 encrypts by default and refuses a
                // container's self-signed certificate; a test database
                // trusts it rather than provisioning real certificates.
                'encrypt' => (string) Env::get('LARAFLOW_TEST_DB_ENCRYPT', 'yes'),
                'trust_server_certificate' => true,
            ],
            default => [],
        };
    }

    /**
     * The driver the suite is currently running against.
     */
    protected function databaseDriver(): string
    {
        return (string) $this->app['config']->get('database.connections.testing.driver');
    }
}
