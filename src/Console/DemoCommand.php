<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\StartsExecutions;
use Laraflow\Demo\DemoWorkflow;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * `laraflow:start` for the one workflow that ships with the package:
 * the same start-and-report path, with the demo's input filled in.
 */
class DemoCommand extends Command
{
    use StartsExecutions;

    protected $signature = 'laraflow:demo
        {--fail : Make the payment step fail on its first attempt so recovery can be demonstrated}
        {--queue : Dispatch the demo workflow to the queue instead of running it in this process}';

    protected $description = 'Run the bundled demo workflow against the durable runtime';

    public function handle(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowDispatcher $dispatcher,
    ): int {
        $input = [
            'sku' => 'SKU-1001',
            'quantity' => 2,
            'fail' => (bool) $this->option('fail'),
        ];

        $this->components->info('Starting '.DemoWorkflow::class);

        if ((bool) $this->option('queue')) {
            return $this->startQueued($runner, $repository, $dispatcher, new DemoWorkflow, $input);
        }

        return $this->startInline($runner, $repository, new DemoWorkflow, $input);
    }
}
