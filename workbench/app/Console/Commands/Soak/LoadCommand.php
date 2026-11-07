<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use Illuminate\Console\Command;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;
use Workbench\App\Console\Commands\Concerns\ReadsInput;
use Workbench\App\Soak\Env;
use Workbench\App\Soak\Ledger;
use Workbench\App\Soak\Stats\StatsSink;
use Workbench\App\Soak\Workload;

/**
 * The load generator: creates executions at a steady rate from the
 * workload's mix, with periodic floods for the tenant held to
 * ceilings, and records every execution it created in the run's
 * ledger so the report can account for what retention later removed.
 *
 * start() and dispatchExecution() are called separately rather than
 * through dispatch(), so a push that fails — the queue being down is
 * one of the faults — still leaves an execution the ledger knows about
 * and the pending scan must find.
 */
final class LoadCommand extends Command
{
    use ReadsInput;
    use RunsUntil;

    protected $signature = 'soak:load
        {--duration=300 : Seconds to generate load for}
        {--rate=8 : Executions created per second}
        {--burst-every=25 : Seconds between floods for the bounded tenant}
        {--burst=25 : Executions in each flood}
        {--inline=5 : Percentage of executions run in this process rather than queued}';

    protected $description = 'Soak: generate mixed workflow load';

    public function handle(WorkflowRunner $runner, WorkflowDispatcher $dispatcher, StatsSink $stats): int
    {
        $runDir = Env::string(Env::RUN_DIR, Env::defaultRunRoot()) ?? Env::defaultRunRoot();
        $ledger = new Ledger($runDir.'/ledger.jsonl');
        $workload = new Workload(basename($runDir));

        $rate = max(1, $this->intOption('rate', 8));
        $burstEvery = max(5, $this->intOption('burst-every', 25));
        $burstSize = max(0, $this->intOption('burst', 25));
        $inlinePercent = max(0, min(100, $this->intOption('inline', 5)));

        $sequence = 0;
        $lastBurst = microtime(true);
        $create = function (string $scenario, ?string $tenant, bool $inline) use (&$sequence, $workload, $runner, $dispatcher, $ledger, $stats): void {
            $sequence++;
            ['workflow' => $workflow, 'input' => $input, 'options' => $options] = $workload->build($scenario, $sequence, $tenant);
            $record = ['kind' => 'created', 'scenario' => $scenario, 'workflow' => $workflow::class, 'tenant' => $options->tenantId, 'marker' => $input['marker']];

            try {
                $execution = $runner->start($workflow, $input, $options);
            } catch (Throwable $exception) {
                $ledger->append($record + ['mode' => 'start_failed', 'error' => $exception::class]);
                $stats->record('load', 'start_failed', null, ['error' => $exception::class]);

                return;
            }

            $record['id'] = $execution->id;

            if ($inline) {
                try {
                    $runner->execute($execution->id, $workflow);
                    $ledger->append($record + ['mode' => 'inline']);
                    $stats->record('load', 'inline');
                } catch (Throwable $exception) {
                    // A doomed saga throws by design; a run that stops at
                    // the first one is not a soak.
                    $ledger->append($record + ['mode' => 'inline', 'error' => $exception::class]);
                    $stats->record('load', 'inline_threw', null, ['error' => $exception::class]);
                }

                return;
            }

            try {
                $dispatcher->dispatchExecution($execution->id);
                $ledger->append($record + ['mode' => 'queued']);
                $stats->record('load', 'queued');
            } catch (Throwable $exception) {
                $ledger->append($record + ['mode' => 'push_failed', 'error' => $exception::class]);
                $stats->record('load', 'push_failed', null, ['error' => $exception::class]);
            }
        };

        $this->runUntil(
            $this->intOption('duration', 300),
            1 / $rate,
            function () use ($create, $workload, $inlinePercent, $burstEvery, $burstSize, &$lastBurst): void {
                if (microtime(true) - $lastBurst >= $burstEvery) {
                    $lastBurst = microtime(true);

                    for ($i = 0; $i < $burstSize; $i++) {
                        $create('linear', 'flood', false);
                    }
                }

                $create($workload->pick(), null, random_int(1, 100) <= $inlinePercent);
            },
            fn (Throwable $exception) => $stats->record('load', 'tick_error', null, ['error' => $exception::class]),
        );

        $stats->flush();
        $this->line("Created {$sequence} executions.");

        return self::SUCCESS;
    }
}
