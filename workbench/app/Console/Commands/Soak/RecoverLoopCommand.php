<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use Workbench\App\Soak\Stats\StatsSink;

/**
 * The scheduler's cron entry, compressed: laraflow:recover every few
 * seconds, so stale claims from killed workers, lost dispatches from a
 * queue outage, and due retries and waits are all picked up the way an
 * application's `schedule:run` would pick them up every minute.
 */
final class RecoverLoopCommand extends Command
{
    use RunsUntil;

    protected $signature = 'soak:recover
        {--duration=300 : Seconds to run for}
        {--every=5 : Seconds between recovery runs}';

    protected $description = 'Soak: run laraflow:recover in a loop';

    public function handle(StatsSink $stats): int
    {
        $this->runUntil(
            $this->intOption('duration', 300),
            $this->floatOption('every', 5.0),
            function () use ($stats): void {
                $started = hrtime(true);
                $exit = Artisan::call('laraflow:recover', ['--json' => true]);
                $output = json_decode(Artisan::output(), true);
                $counts = is_array($output) && isset($output['counts']) && is_array($output['counts']) ? $output['counts'] : [];

                $stats->record('recover', $exit === 0 ? 'ok' : 'exit_'.$exit, (hrtime(true) - $started) / 1_000_000, ['counts' => $counts]);
            },
            fn (Throwable $exception) => $stats->record('recover', 'error', null, ['error' => $exception::class]),
        );

        $stats->flush();

        return self::SUCCESS;
    }
}
