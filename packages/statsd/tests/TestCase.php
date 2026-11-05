<?php

declare(strict_types=1);

namespace Laraflow\StatsD\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\StatsD\StatsDServiceProvider;
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
        return [LaraflowServiceProvider::class, StatsDServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    /**
     * Every family the datagrams describe: the type from `|c`, `|h`, or
     * `|g`, the label names from the tags of every datagram for it.
     *
     * @param  list<string>  $datagrams
     * @return array<string, array{type: string, labels: list<string>}>
     */
    protected function families(array $datagrams, string $dialect = 'dogstatsd'): array
    {
        $types = ['c' => 'counter', 'h' => 'histogram', 'g' => 'gauge'];
        $families = [];

        foreach ($datagrams as $datagram) {
            if ($dialect === 'graphite') {
                $matched = preg_match('/^([a-z_]+)((?:;[a-z_]+=[^;:]*)*):[0-9.]+\|([chg])$/', $datagram, $part);
                preg_match_all('/;([a-z_]+)=/', $part[2] ?? '', $labels);
            } else {
                $matched = preg_match('/^([a-z_]+):[0-9.]+\|([chg])(?:\|#(.*))?$/', $datagram, $part);
                preg_match_all('/(?:^|,)([a-z_]+):/', $part[3] ?? '', $labels);
                $part[3] = $part[2] ?? '';
            }

            if ($matched !== 1 || ! isset($part[1], $part[3], $types[$part[3]])) {
                $this->fail("Not a well-formed datagram: {$datagram}");
            }

            $name = $part[1];
            $families[$name] ??= ['type' => $types[$part[3]], 'labels' => []];
            $families[$name]['labels'] = array_values(array_unique([...$families[$name]['labels'], ...$labels[1]]));
        }

        return $families;
    }
}
