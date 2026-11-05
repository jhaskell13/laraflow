<?php

declare(strict_types=1);

namespace Laraflow\Pulse\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Pulse\PulseServiceProvider;
use Laraflow\Pulse\PulseSink;
use Laravel\Pulse\PulseServiceProvider as LaravelPulseServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Testbench declares this `Application|null`: null only before the
     * application is created, never while a test method runs. Narrowed
     * as the core suite narrows it (tests/TestCase.php).
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, LaravelPulseServiceProvider::class, LaraflowServiceProvider::class, PulseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('pulse.ingest.driver', 'storage');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel/pulse/database/migrations');
    }

    /**
     * Every Laraflow family Pulse now holds: a counter is aggregated by
     * `sum`, a histogram by `avg`, a gauge is a value; the label names
     * are the keys of each series' JSON key.
     *
     * @return array<string, array{type: string, labels: list<string>}>
     */
    protected function families(): array
    {
        $families = [];

        foreach (DB::table('pulse_aggregates')->where('type', 'like', 'laraflow_%')->get(['type', 'key', 'aggregate']) as $row) {
            $type = match ($row->aggregate) {
                'sum' => 'counter',
                'avg', 'max', 'count' => 'histogram',
                default => 'unknown:'.$row->aggregate,
            };

            $this->add($families, (string) $row->type, $type, (string) $row->key);
        }

        foreach (DB::table('pulse_values')->where('type', 'like', 'laraflow_%')->get(['type', 'key']) as $row) {
            $this->add($families, (string) $row->type, 'gauge', (string) $row->key);
        }

        return $families;
    }

    /**
     * @param  array<string, array{type: string, labels: list<string>}>  $families
     */
    private function add(array &$families, string $name, string $type, string $key): void
    {
        if (isset($families[$name]) && $families[$name]['type'] !== $type) {
            // A histogram's count rows must not turn it into a counter.
            $type = $families[$name]['type'] === 'histogram' || $type === 'histogram' ? 'histogram' : 'conflict';
        }

        $families[$name] = [
            'type' => $type,
            'labels' => array_values(array_unique([...($families[$name]['labels'] ?? []), ...array_keys(PulseSink::labels($key))])),
        ];
    }
}
