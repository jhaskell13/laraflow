<?php

declare(strict_types=1);

namespace Workbench\App\Soak;

use Illuminate\Support\Env as LaravelEnv;

/**
 * The soak's environment: which variables switch it on and carry its
 * settings between the orchestrator and the processes it spawns, and
 * the database connection every one of them shares.
 *
 * Deliberately a mirror of the suite's seam (tests/TestCase.php,
 * LARAFLOW_TEST_DB_*) rather than a second one: CI points the soak at
 * an engine with exactly the variables it already uses for the tests,
 * so the two cannot disagree about how an engine is reached.
 */
final class Env
{
    /** Set to 1 on every soak process; the provider does nothing without it. */
    public const ACTIVE = 'LARAFLOW_SOAK';

    /** The run's directory: logs, stats, ledger, report. */
    public const RUN_DIR = 'SOAK_RUN_DIR';

    /** Where this process appends its timing records (JSON lines). */
    public const STATS_FILE = 'SOAK_STATS_FILE';

    /** Seconds added to every Laraflow timestamp this process reads. */
    public const CLOCK_OFFSET = 'SOAK_CLOCK_OFFSET_SECONDS';

    /** The stub provider's base URL, once the orchestrator has started it. */
    public const STUB_URL = 'SOAK_STUB_URL';

    /** The credential the soak connector authenticates with — a sentinel the logs are scanned for. */
    public const TOKEN = 'SOAK_TOKEN';

    /** Seconds after which a claim is stale; short, so recovery is exercised often. */
    public const STALE_AFTER = 'SOAK_STALE_AFTER';

    public const DEFAULT_STALE_AFTER = 45;

    /** Docker container ids the fault injector may stop and start; absent locally. */
    public const DB_CONTAINER = 'SOAK_DB_CONTAINER';

    public const QUEUE_CONTAINER = 'SOAK_QUEUE_CONTAINER';

    /** The sentinel every payload the load generator writes carries. */
    public const PAYLOAD_MARKER = 'soak-payload-';

    public static function staleAfterSeconds(): int
    {
        return max(20, self::int(self::STALE_AFTER, self::DEFAULT_STALE_AFTER));
    }

    /** The queue's redelivery delay: between the job timeout and the stale claim. */
    public static function retryAfterSeconds(): int
    {
        return self::staleAfterSeconds() - 10;
    }

    public static function active(): bool
    {
        if (self::string(self::ACTIVE) === '1') {
            return true;
        }

        // The orchestrator itself is launched by a person or CI, and
        // asking them to remember an environment variable before the
        // command that is the whole point is a trap: a soak:* command
        // is the soak, so it switches the provider on by being invoked.
        $argv = $_SERVER['argv'] ?? null;

        return is_array($argv) && isset($argv[1]) && is_string($argv[1]) && str_starts_with($argv[1], 'soak:');
    }

    public static function string(string $name, ?string $default = null): ?string
    {
        $value = LaravelEnv::get($name);

        if ($value === null || $value === false || $value === '') {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $name, int $default): int
    {
        $value = self::string($name);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function defaultRunRoot(): string
    {
        return self::repositoryRoot().'/storage/soak';
    }

    /**
     * The connection the whole soak runs against, shaped exactly as the
     * suite's: the same variables, the same per-driver options.
     *
     * @return array<string, mixed>
     */
    public static function databaseConnection(): array
    {
        $driver = self::string('LARAFLOW_TEST_DB_DRIVER', 'sqlite') ?? 'sqlite';

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => self::string('LARAFLOW_TEST_DB_DATABASE', self::defaultRunRoot().'/soak.sqlite'),
                'prefix' => '',
                'foreign_key_constraints' => true,
                // Several processes share one file: the playground's
                // reasoning (PlaygroundServiceProvider) applies with more
                // force here, so the same pragmas are set on connect.
                'busy_timeout' => 10000,
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
            ];
        }

        $connection = [
            'driver' => $driver,
            'host' => self::string('LARAFLOW_TEST_DB_HOST', '127.0.0.1'),
            'port' => self::int('LARAFLOW_TEST_DB_PORT', match ($driver) {
                'pgsql' => 5432,
                'sqlsrv' => 1433,
                default => 3306,
            }),
            'database' => self::string('LARAFLOW_TEST_DB_DATABASE', 'laraflow_test'),
            'username' => self::string('LARAFLOW_TEST_DB_USERNAME', 'laraflow'),
            'password' => self::string('LARAFLOW_TEST_DB_PASSWORD', 'secret'),
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
                'encrypt' => self::string('LARAFLOW_TEST_DB_ENCRYPT', 'yes'),
                'trust_server_certificate' => true,
            ],
            default => [],
        };
    }

    /**
     * The variables a spawned process must inherit for it to be part of
     * this run, over whatever the orchestrator's own environment holds.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function forChild(string $runDir, string $statsFile, array $extra = []): array
    {
        $env = [
            self::ACTIVE => '1',
            self::RUN_DIR => $runDir,
            self::STATS_FILE => $statsFile,
            // Laravel's log, and so every Laraflow log line, goes to the
            // process's own captured output; this is what the leak scan
            // reads. Lifecycle logging is switched on for the same reason:
            // more lines means more surface for a sentinel to appear in.
            'LOG_CHANNEL' => 'stderr',
            'LARAFLOW_LOG_LIFECYCLE' => '1',
            // The tenant and connector rate limiters count in the cache,
            // which every process must share. The file store does without
            // a service the soak would otherwise have to run.
            'CACHE_STORE' => 'file',
            // docs/queue-and-recovery.md orders the three timeouts:
            // laraflow.queue.timeout < the connection's retry_after <
            // stale_after. The provider sets the first and last from
            // SOAK_STALE_AFTER; this is the middle one, for both queue
            // backends the soak runs on. A job whose worker was killed is
            // redelivered by the queue after this many seconds and meets
            // the claim recovery may already have taken — the duplicate
            // delivery the contract says resolves safely.
            'DB_QUEUE_RETRY_AFTER' => (string) self::retryAfterSeconds(),
            'REDIS_QUEUE_RETRY_AFTER' => (string) self::retryAfterSeconds(),
        ];

        foreach ([self::STUB_URL, self::TOKEN, self::STALE_AFTER, 'QUEUE_CONNECTION', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_CLIENT'] as $name) {
            $value = self::string($name);

            if ($value !== null) {
                $env[$name] = $value;
            }
        }

        foreach ($extra as $name => $value) {
            $env[(string) $name] = (string) $value;
        }

        return $env;
    }
}
