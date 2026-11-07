<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use Workbench\App\Console\Commands\Concerns\ReadsInput;
use Workbench\App\Soak\Env;
use Workbench\App\Soak\Stats\StatsSink;
use Workbench\App\Soak\Workload;

/**
 * Retention, concurrently with everything else: all three tiers
 * against one workflow's finished executions, while workers are still
 * creating and finishing more of them.
 *
 * Retention bounds are counted in whole days and must be at least one,
 * so nothing a run lasting minutes produces could ever age into reach.
 * This process therefore runs with its clock two days ahead
 * (SOAK_CLOCK_OFFSET_SECONDS, set by the orchestrator), which puts
 * "finished more than a day ago" at everything that has finished.
 */
final class PruneLoopCommand extends Command
{
    use ReadsInput;
    use RunsUntil;

    protected $signature = 'soak:prune
        {--duration=300 : Seconds to run for}
        {--every=15 : Seconds between retention runs}
        {--workflow= : The workflow class whose finished executions may be removed}';

    protected $description = 'Soak: run laraflow:prune in a loop against one workflow';

    public function handle(StatsSink $stats): int
    {
        $workflow = $this->stringOption('workflow') ?? Workload::RETENTION_TARGET;

        if (Env::int(Env::CLOCK_OFFSET, 0) < 86400) {
            $this->components->warn('The clock is not a day ahead; retention will find nothing old enough to touch.');
        }

        $this->runUntil(
            $this->intOption('duration', 300),
            $this->floatOption('every', 15.0),
            function () use ($stats, $workflow): void {
                $started = hrtime(true);
                $exit = Artisan::call('laraflow:prune', [
                    '--scrub-after' => 1,
                    '--prune-attempts-after' => 1,
                    '--delete-after' => 1,
                    '--workflow' => $workflow,
                    '--force' => true,
                    '--json' => true,
                ]);
                $output = json_decode(Artisan::output(), true);
                $report = is_array($output) && isset($output['report']) && is_array($output['report']) ? $output['report'] : [];

                $stats->record('prune', $exit === 0 ? 'ok' : 'exit_'.$exit, (hrtime(true) - $started) / 1_000_000, ['report' => $report]);
            },
            fn (Throwable $exception) => $stats->record('prune', 'error', null, ['error' => $exception::class]),
        );

        $stats->flush();

        return self::SUCCESS;
    }
}
