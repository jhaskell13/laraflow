<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Laraflow\Prometheus\PrometheusSink;

/**
 * Delete every counter and histogram the bridge has stored.
 *
 * Needed when histogram buckets change: Prometheus cannot read a family
 * whose bucket boundaries changed under it, and stored observations are
 * already sorted into the old ones. Counters restart from zero, which
 * Prometheus's `rate()` reads as a counter reset — expected, and the
 * same as a process restart. Gauges are not stored and are unaffected.
 */
final class WipeCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'laraflow:prometheus-wipe {--force : Skip the production confirmation}';

    protected $description = 'Delete the counters and histograms laraflow/prometheus has stored';

    public function handle(): int
    {
        if (! $this->laravel->bound(PrometheusSink::class)) {
            $this->components->error('laraflow/prometheus is not enabled; there is nothing to wipe.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->laravel->make(PrometheusSink::class)->wipe();
        $this->components->info('Stored counters and histograms deleted. Counters restart from zero.');

        return self::SUCCESS;
    }
}
