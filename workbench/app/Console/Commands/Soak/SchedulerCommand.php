<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use Workbench\App\Soak\Env;
use Workbench\App\Soak\Stats\StatsSink;

/**
 * One scheduler, ticking far more often than the minute it schedules
 * so that racing it against two others — each with its own idea of
 * what time it is, through SOAK_CLOCK_OFFSET_SECONDS — is the normal
 * case rather than a coincidence.
 */
final class SchedulerCommand extends Command
{
    use RunsUntil;

    protected $signature = 'soak:scheduler
        {--duration=300 : Seconds to run for}
        {--every=5 : Seconds between scheduler ticks}';

    protected $description = 'Soak: run laraflow:schedule in a loop';

    public function handle(StatsSink $stats): int
    {
        $offset = Env::int(Env::CLOCK_OFFSET, 0);

        $this->runUntil(
            $this->intOption('duration', 300),
            $this->floatOption('every', 5.0),
            function () use ($stats, $offset): void {
                $started = hrtime(true);
                $exit = Artisan::call('laraflow:schedule', ['--json' => true]);
                $output = json_decode(Artisan::output(), true);
                $claimed = is_array($output) && isset($output['actions']) && is_array($output['actions']) ? count($output['actions']) : null;

                $stats->record('schedule', $exit === 0 ? 'ok' : 'exit_'.$exit, (hrtime(true) - $started) / 1_000_000, ['offset' => $offset, 'ticks' => $claimed]);
            },
            fn (Throwable $exception) => $stats->record('schedule', 'error', null, ['error' => $exception::class, 'offset' => $offset]),
        );

        $stats->flush();

        return self::SUCCESS;
    }
}
