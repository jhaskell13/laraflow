<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Prometheus\PrometheusServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Testbench declares this `Application|null`: null only before the
     * application is created, never while a test method runs. Narrowed
     * as the core suite narrows it (tests/TestCase.php).
     *
     * @var Application
     */
    protected $app;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaraflowServiceProvider::class, PrometheusServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    /**
     * Every family a scrape exposes: its type from `# TYPE`, its label
     * names from its samples. A histogram's `_bucket`, `_sum`, and
     * `_count` lines are one family, and `le` is the histogram's own.
     *
     * @return array<string, array{type: string, labels: list<string>}>
     */
    protected function families(string $scrape): array
    {
        $families = [];

        foreach (explode("\n", $scrape) as $line) {
            if (preg_match('/^# TYPE (\S+) (\S+)$/', $line, $type) === 1) {
                $families[$type[1]] = ['type' => $type[2], 'labels' => []];

                continue;
            }

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([a-z_]+?)(_bucket|_sum|_count)?(?:\{(.*)\})? /', $line, $sample) !== 1) {
                continue;
            }

            $suffixed = $sample[1].($sample[2] ?? '');
            $name = isset($families[$suffixed]) ? $suffixed : $sample[1];
            preg_match_all('/([a-z_]+)="/', $sample[3] ?? '', $labels);
            $families[$name] ??= ['type' => 'untyped', 'labels' => []];

            $families[$name]['labels'] = array_values(array_unique(array_merge(
                $families[$name]['labels'],
                array_values(array_diff($labels[1], ['le'])),
            )));
        }

        return $families;
    }
}
