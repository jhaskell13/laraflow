<?php

declare(strict_types=1);

namespace Laraflow\StatsD\Console;

use Illuminate\Console\Command;
use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\SnapshotMetrics;
use Laraflow\Observability\Snapshots;
use Laraflow\StatsD\StatsDSink;
use Psr\Clock\ClockInterface;

/**
 * Push the snapshot's gauges. StatsD is push-only, so nothing asks for
 * them as a Prometheus scrape does; the application schedules this:
 *
 * ```php
 * Schedule::command('laraflow:statsd-gauges')->everyMinute();
 * ```
 */
final class PushGaugesCommand extends Command
{
    protected $signature = 'laraflow:statsd-gauges';

    protected $description = 'Push Laraflow\'s gauges — work by status, due retries, stalled schedules — to StatsD';

    public function handle(Snapshots $snapshots, ClockInterface $clock): int
    {
        if (! $this->laravel->bound(StatsDSink::class)) {
            $this->components->error('laraflow/statsd is not enabled; nothing was pushed.');

            return self::FAILURE;
        }

        (new SnapshotMetrics($this->laravel->make(StatsDSink::class), $this->laravel->make(MetricCatalog::class)))
            ->publish($snapshots->take($clock->now()));

        return self::SUCCESS;
    }
}
