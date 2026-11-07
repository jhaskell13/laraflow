<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Laraflow\Integration\ConfigCredentialProvider;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\Http\LaravelHttpTransport;
use Laraflow\Observability\Tracing\NullTracer;
use Laraflow\Observability\Tracing\Tracer;
use Laraflow\Queue\QueueChildDelivery;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\PassthroughRedactor;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\Repositories\EloquentIdempotencyStore;
use Laraflow\Workflow\Repositories\EloquentScheduleRepository;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\Tenancy\ConfigTenantPolicy;
use Laraflow\Workflow\Tenancy\TenantPolicy;
use Throwable;

/**
 * @internal The engine behind `laraflow:upgrade`. Use the command.
 *
 * What an installed application has to deal with before, or because
 * of, moving to a new Laraflow. Every check is a read: nothing here
 * writes, migrates, or dispatches.
 *
 * The checks are of two kinds. Most are true of any upgrade — are the
 * migrations run, has the published configuration drifted, is a
 * workflow's code ahead of the plans its in-flight executions are
 * running. One is specific to a version: what this major removed, for
 * which the honest thing is to name it and let a person check their
 * own scripts rather than pretend to scan them.
 */
final class UpgradeInspection
{
    /**
     * What this major release removed. Named rather than detected: the
     * package cannot see an application's deploy scripts, its runbooks,
     * or its CI, and a checker that says "nothing to do" because it did
     * not look is worse than one that says "look here".
     *
     * @var list<array{what: string, instead: string}>
     */
    private const REMOVED_IN_THIS_MAJOR = [
        [
            'what' => 'The generator aliases `make:workflow`, `make:step`, and `make:connector`',
            'instead' => 'Use `laraflow:make-workflow`, `laraflow:make-step`, and `laraflow:make-connector`. Deprecated in 0.6.0, removed in 1.0.',
        ],
    ];

    /**
     * The contracts an application is invited to replace, each with the
     * implementation this package binds by default. A replacement is
     * not a problem — it is the documented way to swap a piece — but it
     * is the thing most likely to need work after an upgrade, so an
     * upgrade should be told which ones exist.
     *
     * Detected by comparing against the default rather than by the
     * implementation's namespace: a namespace test calls anything under
     * `Laraflow\` the package's own, which is wrong for a test double
     * and for any application that happens to share the prefix.
     *
     * @var array<class-string, array{0: class-string, 1: string}>
     */
    private const REBINDABLE = [
        ExecutionRepository::class => [EloquentExecutionRepository::class, 'the execution store; its ExecutionStore half stays Experimental at 1.0 and may change in a minor'],
        ScheduleRepository::class => [EloquentScheduleRepository::class, 'schedule and tick storage'],
        IdempotencyStore::class => [EloquentIdempotencyStore::class, 'the idempotency store'],
        TenantPolicy::class => [ConfigTenantPolicy::class, 'tenant ceilings'],
        ChildDelivery::class => [QueueChildDelivery::class, 'how child executions are delivered'],
        HttpTransport::class => [LaravelHttpTransport::class, 'the connector wire'],
        CredentialProvider::class => [ConfigCredentialProvider::class, 'where connector credentials come from'],
        ErrorRedactor::class => [PassthroughRedactor::class, 'what is stripped from every message Laraflow retains'],
        Tracer::class => [NullTracer::class, 'the tracing hook'],
    ];

    public function __construct(
        private readonly Container $container,
        private readonly ExecutionReader $executions,
        private readonly WorkflowResolver $resolver,
    ) {}

    /**
     * @return list<UpgradeCheck>
     */
    public function run(): array
    {
        return [
            $this->migrations(),
            $this->publishedMigrations(),
            $this->publishedConfig(),
            $this->customBindings(),
            $this->inFlightPlans(),
            $this->removedThisMajor(),
        ];
    }

    /**
     * Laraflow migrations the application has not run. The one check
     * whose answer is always "run `php artisan migrate`".
     */
    private function migrations(): UpgradeCheck
    {
        if (! $this->container->bound('migrator')) {
            return UpgradeCheck::review('migrations', 'No migrator is bound; migrations could not be checked.');
        }

        $migrator = $this->container->make('migrator');

        try {
            if (! $migrator->repositoryExists()) {
                return UpgradeCheck::action('migrations', 'The migration table does not exist; this install has never migrated.', ['Run: php artisan migrate']);
            }

            $ran = $migrator->getRepository()->getRan();
        } catch (Throwable $exception) {
            return UpgradeCheck::review('migrations', 'The migration state could not be read: '.$exception::class);
        }

        $pending = [];

        foreach (array_keys($migrator->getMigrationFiles([self::packageMigrationPath()])) as $name) {
            if (! in_array($name, $ran, true)) {
                $pending[] = (string) $name;
            }
        }

        if ($pending === []) {
            return UpgradeCheck::ok('migrations', 'Every Laraflow migration has been run.');
        }

        sort($pending);

        return UpgradeCheck::action(
            'migrations',
            count($pending).' Laraflow migration(s) have not been run. Run: php artisan migrate',
            $pending,
        );
    }

    /**
     * An application that published the migrations owns copies, and
     * Laraflow's later ones do not appear in them by themselves. The
     * schema freeze means the shipped files never change, so a name
     * present in the package and absent from the published directory is
     * the whole of the problem.
     */
    private function publishedMigrations(): UpgradeCheck
    {
        $published = $this->applicationMigrationPath();

        if ($published === null || ! is_dir($published)) {
            return UpgradeCheck::ok('published_migrations', 'The migrations are loaded from the package; nothing to reconcile.');
        }

        $theirs = [];

        foreach (glob($published.'/*_laraflow_*.php') ?: [] as $file) {
            $theirs[] = basename($file, '.php');
        }

        if ($theirs === []) {
            return UpgradeCheck::ok('published_migrations', 'The migrations are loaded from the package; nothing to reconcile.');
        }

        $missing = [];

        foreach (glob(self::packageMigrationPath().'/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if (! in_array($name, $theirs, true)) {
                $missing[] = $name;
            }
        }

        if ($missing === []) {
            return UpgradeCheck::ok('published_migrations', 'The published migrations match the package.');
        }

        sort($missing);

        return UpgradeCheck::action(
            'published_migrations',
            count($missing).' migration(s) shipped with this version are not in the published copies.',
            [...$missing, 'Run: php artisan vendor:publish --tag=laraflow-migrations'],
        );
    }

    /**
     * A published `config/laraflow.php` is a snapshot of the defaults
     * at the moment it was published. New keys still work — the
     * provider merges the package's defaults underneath — so a missing
     * key is a note, not a fault. A key the package no longer defines
     * is the interesting one: it is a setting the application believes
     * it has set and nothing reads.
     */
    private function publishedConfig(): UpgradeCheck
    {
        $path = $this->applicationConfigPath();

        if ($path === null || ! is_file($path)) {
            return UpgradeCheck::ok('config', 'No published configuration; the package defaults are in force.');
        }

        try {
            /** @var array<string, mixed> $published */
            $published = require $path;
            /** @var array<string, mixed> $shipped */
            $shipped = require self::packageConfigPath();
        } catch (Throwable $exception) {
            return UpgradeCheck::review('config', 'The configuration could not be read: '.$exception::class);
        }

        $theirs = self::leafKeys($published);
        $ours = self::leafKeys($shipped);

        // `integrations` is the application's own to shape: a connector
        // it declares is a key the package will never know.
        $unknown = array_values(array_filter(
            array_diff($theirs, $ours),
            static fn (string $key): bool => ! str_starts_with($key, 'integrations.') && ! str_starts_with($key, 'tenants.overrides') && ! str_starts_with($key, 'schedules.workflows'),
        ));
        $absent = array_values(array_diff($ours, $theirs));

        if ($unknown === [] && $absent === []) {
            return UpgradeCheck::ok('config', 'The published configuration matches this version\'s keys.');
        }

        $details = [];

        foreach ($unknown as $key) {
            $details[] = "laraflow.{$key} is set but this version defines no such key — renamed or removed.";
        }

        foreach ($absent as $key) {
            $details[] = "laraflow.{$key} is new in this version; the package default applies until the published file names it.";
        }

        // A key that no longer exists is a silent misconfiguration; a
        // key that is merely new is working as designed.
        return $unknown === []
            ? UpgradeCheck::review('config', count($absent).' new configuration key(s) are not in the published file.', $details)
            : UpgradeCheck::action('config', count($unknown).' configuration key(s) are set but unknown to this version.', $details);
    }

    private function customBindings(): UpgradeCheck
    {
        $custom = [];

        foreach (self::REBINDABLE as $contract => [$default, $what]) {
            if (! $this->container->bound($contract)) {
                continue;
            }

            try {
                $implementation = $this->container->make($contract)::class;
            } catch (Throwable $exception) {
                $custom[] = class_basename($contract).' is bound but cannot be resolved: '.$exception::class;

                continue;
            }

            if ($implementation !== $default) {
                $custom[] = "{$implementation} replaces ".class_basename($contract)." — {$what}.";
            }
        }

        return $custom === []
            ? UpgradeCheck::ok('custom_bindings', 'No Laraflow contract is replaced; every default binding is in force.')
            : UpgradeCheck::review('custom_bindings', count($custom).' Laraflow contract(s) are replaced; re-read their contracts after upgrading.', $custom);
    }

    /**
     * The case docs/upgrading.md warns about: a deploy changes a
     * workflow's `steps()`, and executions created before it are
     * validated against the plan they recorded on every claim. Without
     * a declared plan migration they fail with
     * WorkflowDefinitionMismatchException — deliberately, because
     * silence would mean running the wrong steps. This finds them
     * before the deploy rather than after.
     */
    private function inFlightPlans(): UpgradeCheck
    {
        $unfinished = [ExecutionStatus::Pending, ExecutionStatus::Running, ExecutionStatus::Retrying, ExecutionStatus::Waiting, ExecutionStatus::Compensating];

        try {
            $page = $this->executions->list(new ExecutionFilter(status: $unfinished), ExecutionReader::MAX_PAGE_SIZE);
        } catch (Throwable $exception) {
            return UpgradeCheck::review('in_flight_plans', 'Executions could not be read: '.$exception::class);
        }

        $stranded = [];
        $unresolvable = [];
        $seen = [];

        foreach ($page->executions as $execution) {
            if (isset($seen[$execution->workflow])) {
                continue;
            }

            $seen[$execution->workflow] = true;

            try {
                $workflow = $this->resolver->resolve($execution->id, $execution->workflow);
            } catch (Throwable) {
                $unresolvable[] = $execution->workflow;

                continue;
            }

            $steps = $this->executions->steps($execution);
            $planned = array_values(array_map(
                static fn (StepExecution $step): string => $step->step,
                array_filter($steps, static fn (StepExecution $step): bool => $step->compensatesStepExecutionId === null),
            ));

            if ($planned !== [] && $planned !== $workflow->steps()) {
                $stranded[] = $execution->workflow;
            }
        }

        $details = [];

        foreach ($unresolvable as $workflow) {
            $details[] = "{$workflow} has unfinished executions but cannot be built — the class is gone or no longer a Workflow.";
        }

        foreach ($stranded as $workflow) {
            $details[] = "{$workflow} has unfinished executions whose recorded plan differs from its current steps(). "
                .'Bump version() and declare the matching planMigrations() entry before deploying, or those executions fail on their next claim.';
        }

        if ($details === []) {
            return UpgradeCheck::ok('in_flight_plans', 'Every unfinished execution\'s plan matches the workflow it runs.');
        }

        return UpgradeCheck::action(
            'in_flight_plans',
            count($details).' workflow(s) with unfinished executions need attention before a deploy.',
            $details,
        );
    }

    private function removedThisMajor(): UpgradeCheck
    {
        $details = [];

        foreach ($this->removals() as $removal) {
            $details[] = $removal['what'].' — '.$removal['instead'];
        }

        // A release that removes nothing says so, rather than being
        // absent from the report and leaving a reader to wonder.
        if ($details === []) {
            return UpgradeCheck::ok('removed', 'This release removes nothing.');
        }

        return UpgradeCheck::review('removed', count($details).' removal(s) in this major; check scripts, schedulers, and runbooks by hand.', $details);
    }

    /**
     * Read through a typed accessor rather than the constant directly:
     * the list is empty in any release that removes nothing, and the
     * branch above has to stay reachable for that release.
     *
     * @return list<array{what: string, instead: string}>
     */
    private function removals(): array
    {
        return self::REMOVED_IN_THIS_MAJOR;
    }

    /**
     * Every leaf key of a configuration array, dotted. A leaf is a
     * value or an empty array: `tenants.overrides` is a setting, not a
     * namespace, even when an application has filled it.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function leafKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $keys = [...$keys, ...self::leafKeys($value, $dotted)];

                continue;
            }

            $keys[] = $dotted;
        }

        return $keys;
    }

    private static function packageMigrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    private static function packageConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/laraflow.php';
    }

    private function applicationMigrationPath(): ?string
    {
        $app = $this->container;

        return $app instanceof Application ? $app->databasePath('migrations') : null;
    }

    private function applicationConfigPath(): ?string
    {
        $app = $this->container;

        return $app instanceof Application ? $app->configPath('laraflow.php') : null;
    }
}
