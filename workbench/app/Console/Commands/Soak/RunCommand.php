<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Laraflow\Observability\Snapshots;
use RuntimeException;
use Throwable;
use Workbench\App\Console\Commands\Concerns\ReadsInput;
use Workbench\App\Soak\Env;
use Workbench\App\Soak\Finding;
use Workbench\App\Soak\Invariants;
use Workbench\App\Soak\Process\Fleet;
use Workbench\App\Soak\Process\ManagedProcess;
use Workbench\App\Soak\Report;
use Workbench\App\Soak\Workload;

/**
 * The orchestrator (docs/soak.md).
 *
 * Resets the database, starts the stub provider, and spawns the fleet:
 * queue workers, skewed schedulers, the recovery and retention loops,
 * the signaller, and the load generator. While the load runs it kills
 * workers at random and — where the container ids are known — stops
 * and restarts the database and the queue backend. When the load ends
 * it drains the waits and lets recovery settle the records, stops
 * everything, checks every invariant, measures what the contract says
 * it measures, and writes the report. Exits non-zero if any invariant
 * failed or the run did not settle.
 */
final class RunCommand extends Command
{
    use ReadsInput;
    use RunsUntil;

    private const TERMINAL = ['completed', 'failed', 'cancelled'];

    protected $signature = 'soak:run
        {--duration=300 : Seconds of load}
        {--workers=4 : Queue workers}
        {--schedulers=3 : Scheduler processes, with skewed clocks}
        {--rate=8 : Executions created per second}
        {--kill-every=15 : Seconds between worker kills (jittered); 0 disables}
        {--fault-every=120 : Seconds between container stop/start faults; 0 disables}
        {--down=10 : Seconds a stopped container stays down}
        {--settle-timeout=1800 : Hard ceiling, in seconds, on the settling phase}
        {--stall-after=180 : Give up settling when the unfinished count has not fallen for this many seconds}
        {--run-dir= : Where to write this run (default: storage/soak/<timestamp>)}
        {--keep-database : Do not migrate:fresh first}';

    protected $description = 'Soak: run the whole suite — load, faults, settle, verify, report';

    private Fleet $fleet;

    private string $runDir = '';

    private string $statsDir = '';

    private string $logsDir = '';

    private string $token = '';

    private int $loadSeconds = 300;

    /** @var list<array<string, mixed>> */
    private array $faults = [];

    private int $kills = 0;

    private float $startedAt = 0.0;

    public function handle(DatabaseManager $databases, Container $container): int
    {
        $db = $databases->connection();
        $this->fleet = new Fleet;
        $this->startedAt = microtime(true);
        $this->loadSeconds = max(30, $this->intOption('duration', 300));
        $workers = max(1, $this->intOption('workers', 4));
        $schedulers = max(0, $this->intOption('schedulers', 3));
        $rate = max(1, $this->intOption('rate', 8));
        $settleTimeout = max(60, $this->intOption('settle-timeout', 1800));
        $stallAfter = max(30, $this->intOption('stall-after', 180));

        $this->prepareRunDirectory();
        $this->token = 'soak-secret-'.bin2hex(random_bytes(8));
        putenv(Env::TOKEN.'='.$this->token);

        $driver = $db->getDriverName();
        $queue = Env::string('QUEUE_CONNECTION', 'database') ?? 'database';

        $this->components->info(sprintf('Soak run %s on %s with the %s queue: %ds of load at %d/s, %d workers, %d schedulers.', basename($this->runDir), $driver, $queue, $this->loadSeconds, $rate, $workers, $schedulers));

        if (! (bool) $this->option('keep-database')) {
            $this->components->task('Fresh database', function (): bool {
                $this->callSilently('migrate:fresh', ['--force' => true]);

                return true;
            });
        }

        $stubUrl = $this->startStubProvider();
        putenv(Env::STUB_URL.'='.$stubUrl);

        try {
            $this->spawnFleet($workers, $schedulers, $rate, $settleTimeout);
            $this->runLoad($db);
            $settled = $this->settle($db, $settleTimeout, $stallAfter);
        } finally {
            $this->components->task('Stopping every process', function (): bool {
                $this->fleet->stopAll(15);

                return true;
            });
        }

        $bench = $this->benchmark($db, $container);

        $this->components->task('Checking invariants', fn (): bool => true);
        $findings = (new Invariants($db, $container, $this->runDir, $this->token, Workload::RETENTION_TARGET))->check();

        if (! $settled) {
            $findings[] = new Finding('settled_in_time', 'The run reached rest rather than stalling', 1, ['executions were still unfinished when settling gave up; see the console output for whether it stalled or hit the ceiling']);
        }

        $meta = [
            'driver' => $driver,
            'queue' => $queue,
            'load_seconds' => $this->loadSeconds,
            'rate' => $rate,
            'workers' => $workers,
            'schedulers' => $schedulers,
            'kills' => $this->kills,
            'settled' => $settled,
            'wall_seconds' => (int) round(microtime(true) - $this->startedAt),
            'php' => PHP_VERSION,
            'started_at' => (new DateTimeImmutable('@'.(int) $this->startedAt))->format(DATE_ATOM),
        ];

        $report = (new Report($db, $this->runDir))->build($meta, $findings, $bench, $this->faults);
        $advice = Report::compareToBaseline($report, Env::repositoryRoot().'/workbench/soak/baseline.json');
        $markdown = Report::markdown($report, $advice);

        file_put_contents($this->runDir.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($this->runDir.'/report.md', $markdown);

        $summary = Env::string('GITHUB_STEP_SUMMARY');

        if ($summary !== null) {
            file_put_contents($summary, $markdown."\n", FILE_APPEND);
        }

        $this->newLine();
        $this->line($markdown);
        $this->components->info("Report written to {$this->runDir}/report.json");

        return ($report['passed'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function prepareRunDirectory(): void
    {
        $this->runDir = $this->stringOption('run-dir') ?? Env::defaultRunRoot().'/'.date('Ymd-His');
        $this->statsDir = $this->runDir.'/stats';
        $this->logsDir = $this->runDir.'/logs';

        foreach ([$this->runDir, $this->statsDir, $this->logsDir] as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException("Could not create {$dir}.");
            }
        }

        putenv(Env::RUN_DIR.'='.$this->runDir);

        $sqlite = config('database.connections.soak.database');

        if (config('database.connections.soak.driver') === 'sqlite' && is_string($sqlite) && ! is_file($sqlite)) {
            touch($sqlite);
        }
    }

    /**
     * PHP's built-in server on a free port, serving the stub provider,
     * waited for until it answers.
     */
    private function startStubProvider(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Could not find a free port for the stub provider.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = is_string($name) ? (int) substr($name, (int) strrpos($name, ':') + 1) : 0;
        $url = "http://127.0.0.1:{$port}";

        $this->fleet->add($this->process('stub', 'stub-server', [PHP_BINARY, '-S', "127.0.0.1:{$port}", Env::repositoryRoot().'/workbench/soak/stub-server.php'], [Env::TOKEN => $this->token]));

        for ($i = 0; $i < 50; $i++) {
            $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);

            if (@file_get_contents("{$url}/health", false, $context) !== false) {
                return $url;
            }

            usleep(100_000);
        }

        throw new RuntimeException('The stub provider never answered.');
    }

    private function spawnFleet(int $workers, int $schedulers, int $rate, int $settleTimeout): void
    {
        $offsets = [0, 25, -25, 50, -50];

        $this->components->task('Starting the fleet', function () use ($workers, $schedulers, $rate, $settleTimeout, $offsets): bool {
            for ($i = 1; $i <= $workers; $i++) {
                $this->spawnWorker($i);
            }

            for ($i = 0; $i < $schedulers; $i++) {
                $offset = $offsets[$i % count($offsets)];
                $this->fleet->add($this->testbench('scheduler', "scheduler-{$i}-skew{$offset}", ['soak:scheduler', '--duration='.$this->loadSeconds, '--every=5'], [Env::CLOCK_OFFSET => (string) $offset]));
            }

            $this->fleet->add($this->testbench('recover', 'recover', ['soak:recover', '--duration='.($this->loadSeconds + $settleTimeout + 60), '--every=5']));
            $this->fleet->add($this->testbench('prune', 'prune', ['soak:prune', '--duration='.$this->loadSeconds, '--every=15', '--workflow='.Workload::RETENTION_TARGET], [Env::CLOCK_OFFSET => '172800']));
            $this->fleet->add($this->testbench('signal', 'signaller', ['soak:signal', '--duration='.$this->loadSeconds]));
            $this->fleet->add($this->testbench('load', 'load', ['soak:load', '--duration='.$this->loadSeconds, '--rate='.$rate]));

            return true;
        });
    }

    private function spawnWorker(int $n): void
    {
        $this->fleet->add($this->testbench('worker', "worker-{$n}", ['queue:work', '--sleep=1', '--tries=1', '--max-time='.($this->loadSeconds * 4)]));
    }

    /**
     * The load window: keep the workers up to strength, kill one now and
     * then, stop and start containers, and say how it is going.
     */
    private function runLoad(Connection $db): void
    {
        $killEvery = max(0, $this->intOption('kill-every', 15));
        $faultEvery = max(0, $this->intOption('fault-every', 120));
        $down = max(2, $this->intOption('down', 10));
        $containers = [];

        foreach (['database' => Env::DB_CONTAINER, 'queue' => Env::QUEUE_CONTAINER] as $kind => $variable) {
            $id = Env::string($variable);

            if ($id !== null) {
                $containers[] = ['kind' => $kind, 'id' => $id];
            }
        }

        if ($containers === [] && $faultEvery > 0) {
            $this->components->warn('No container ids in '.Env::DB_CONTAINER.' / '.Env::QUEUE_CONTAINER.': worker kills only, no database or queue outages this run.');
        }

        $nextKill = microtime(true) + $this->jitter($killEvery);
        $nextFault = microtime(true) + $faultEvery;
        $restartAt = null;
        $restartId = null;
        $faultIndex = 0;
        $workerSerial = 100;
        $lastProgress = 0.0;

        $this->runUntil($this->loadSeconds, 1.0, function () use ($db, $killEvery, $faultEvery, $down, $containers, &$nextKill, &$nextFault, &$restartAt, &$restartId, &$faultIndex, &$workerSerial, &$lastProgress): void {
            $now = microtime(true);

            foreach ($this->fleet->dead('worker') as $dead) {
                $this->fleet->forget($dead);
                $this->spawnWorker(++$workerSerial);
            }

            if ($killEvery > 0 && $now >= $nextKill) {
                $victim = $this->fleet->killRandom('worker');

                if ($victim !== null) {
                    $this->kills++;
                    $this->fault('worker_killed', ['process' => $victim->name, 'pid' => $victim->pid(), 'uptime_s' => round($victim->uptimeSeconds(), 1)]);
                }

                $nextKill = $now + $this->jitter($killEvery);
            }

            if ($restartAt !== null && $now >= $restartAt && $restartId !== null) {
                $this->docker('start', $restartId);
                $this->fault('container_started', ['container' => $restartId]);
                $restartAt = null;
                $restartId = null;
                $db->disconnect();
            }

            if ($faultEvery > 0 && $containers !== [] && $restartAt === null && $now >= $nextFault) {
                ['kind' => $kind, 'id' => $id] = $containers[$faultIndex % count($containers)];
                $faultIndex++;
                $this->docker('stop', $id, ['-t', '1']);
                $this->fault('container_stopped', ['container' => $id, 'kind' => $kind, 'down_s' => $down]);
                $restartAt = $now + $down;
                $restartId = $id;
                $nextFault = $now + $faultEvery;
            }

            if ($now - $lastProgress >= 10) {
                $lastProgress = $now;
                $this->progress($db, 'load');
            }
        });
    }

    /**
     * After the load: no more kills or outages, the schedulers and the
     * retention loop are stopped, the signaller drains every wait, and
     * recovery keeps delivering until nothing is left unfinished.
     */
    /**
     * After the load: no more kills or outages, the schedulers and the
     * retention loop are stopped, the signaller drains every wait, and
     * recovery keeps delivering until nothing is left unfinished.
     *
     * Stopping is decided by *progress*, not by a clock. A backlog can
     * take many minutes to drain — a killed worker's job is invisible
     * until the queue's retry_after returns it — and failing a run that
     * is still draining healthily would be the flaky gate decision 0013
     * declines to build. So the run settles when nothing is unfinished,
     * and gives up only when the unfinished count has not fallen for
     * $stallSeconds, or when the hard ceiling is reached. Both of those
     * are real findings; a slow drain is not.
     */
    private function settle(Connection $db, int $timeout, int $stallSeconds): bool
    {
        $this->components->info('Load finished; settling.');
        $this->fleet->stopRole('load', 5);
        $this->fleet->stopRole('scheduler', 5);
        $this->fleet->stopRole('prune', 10);
        $this->fleet->stopRole('signal', 5);
        $this->fleet->add($this->testbench('signal', 'signaller-drain', ['soak:signal', '--duration='.$timeout, '--drain']));

        $quietPolls = 0;
        $workerSerial = 500;
        $settled = false;
        $stalled = false;
        $fewest = PHP_INT_MAX;
        $improvedAt = microtime(true);

        $this->runUntil($timeout, 5.0, function () use ($db, $stallSeconds, &$quietPolls, &$workerSerial, &$settled, &$stalled, &$fewest, &$improvedAt): void {
            if ($settled || $stalled) {
                return;
            }

            foreach ($this->fleet->dead('worker') as $dead) {
                $this->fleet->forget($dead);
                $this->spawnWorker(++$workerSerial);
            }

            $unfinished = $this->unfinished($db);
            $this->progress($db, 'settle');

            if ($unfinished < $fewest) {
                $fewest = $unfinished;
                $improvedAt = microtime(true);
            }

            // Two quiet polls, not one: a count that reaches zero while
            // a worker is mid-step would otherwise settle the run early.
            $quietPolls = $unfinished === 0 ? $quietPolls + 1 : 0;

            if ($quietPolls >= 2) {
                $settled = true;

                return;
            }

            if (microtime(true) - $improvedAt >= $stallSeconds) {
                $stalled = true;
            }
        });

        if ($settled) {
            $this->components->info('Settled: every execution is at rest.');

            return true;
        }

        $this->components->error($stalled
            ? "Stalled: {$fewest} execution(s) unfinished and no progress for {$stallSeconds}s."
            : "Not settled within the {$timeout}s ceiling; {$fewest} execution(s) were still unfinished.");

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmark(Connection $db, Container $container): array
    {
        $bench = [];

        try {
            $snapshots = $container->make(Snapshots::class);
            $timings = [];

            for ($i = 0; $i < 3; $i++) {
                $started = hrtime(true);
                $snapshots->take(new DateTimeImmutable);
                $timings[] = (hrtime(true) - $started) / 1_000_000;
            }

            sort($timings);
            $bench['snapshot_ms'] = round($timings[1], 2);
        } catch (Throwable $exception) {
            $bench['snapshot_ms'] = null;
            $bench['snapshot_error'] = $exception::class;
        }

        $bench['executions_in_table'] = $db->table('laraflow_workflow_executions')->count();
        $bench['step_rows_in_table'] = $db->table('laraflow_step_executions')->count();
        $bench['attempt_rows_in_table'] = $db->table('laraflow_step_attempts')->count();

        return $bench;
    }

    private function unfinished(Connection $db): int
    {
        try {
            return $db->table('laraflow_workflow_executions')->whereNotIn('status', self::TERMINAL)->count();
        } catch (Throwable) {
            $db->disconnect();

            return PHP_INT_MAX;
        }
    }

    private function progress(Connection $db, string $phase): void
    {
        $elapsed = (int) (microtime(true) - $this->startedAt);
        $clock = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);

        try {
            $rows = $db->table('laraflow_workflow_executions')->select('status', $db->raw('count(*) as n'))->groupBy('status')->get();
            $counts = [];

            foreach ($rows as $row) {
                $counts[] = sprintf('%s=%d', (string) $row->status, (int) $row->n);
            }

            $this->line(sprintf('[%s %s] %s | workers=%d kills=%d faults=%d', $clock, $phase, implode(' ', $counts), count($this->fleet->running('worker')), $this->kills, count($this->faults)));
        } catch (Throwable $exception) {
            $db->disconnect();
            $this->line(sprintf('[%s %s] database unreachable (%s) | workers=%d kills=%d faults=%d', $clock, $phase, class_basename($exception), count($this->fleet->running('worker')), $this->kills, count($this->faults)));
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function fault(string $kind, array $detail): void
    {
        $this->faults[] = ['kind' => $kind, 'at' => round(microtime(true) - $this->startedAt, 1)] + $detail;
        $this->line(sprintf('<fg=yellow>[fault]</> %s %s', $kind, json_encode($detail)));
    }

    /**
     * @param  list<string>  $extra
     */
    private function docker(string $action, string $container, array $extra = []): void
    {
        $process = $this->process('docker', "docker-{$action}", ['docker', $action, ...$extra, $container], []);
        $process->start();

        for ($i = 0; $i < 600 && $process->isRunning(); $i++) {
            usleep(100_000);
        }
    }

    private function jitter(int $seconds): float
    {
        return $seconds <= 0 ? PHP_FLOAT_MAX : $seconds * (0.7 + random_int(0, 60) / 100);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    private function testbench(string $role, string $name, array $arguments, array $env = []): ManagedProcess
    {
        return $this->process($role, $name, [PHP_BINARY, Env::repositoryRoot().'/vendor/bin/testbench', ...$arguments], $env);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function process(string $role, string $name, array $command, array $env): ManagedProcess
    {
        return new ManagedProcess(
            $role,
            $name,
            $command,
            Env::forChild($this->runDir, "{$this->statsDir}/{$name}-".bin2hex(random_bytes(2)).'.jsonl', $env),
            "{$this->logsDir}/{$name}.log",
            Env::repositoryRoot(),
        );
    }
}
