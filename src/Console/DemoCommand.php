<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Demo\DemoWorkflow;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 */
class DemoCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:demo
        {--fail : Make the payment step fail on its first attempt so recovery can be demonstrated}';

    protected $description = 'Run the bundled demo workflow against the durable runtime';

    public function handle(WorkflowRunner $runner, ExecutionRepository $repository): int
    {
        $input = [
            'sku' => 'SKU-1001',
            'quantity' => 2,
            'fail' => (bool) $this->option('fail'),
        ];

        $this->components->info('Starting '.DemoWorkflow::class);

        $execution = $runner->start(new DemoWorkflow, $input);

        try {
            $execution = $runner->execute($execution->id, new DemoWorkflow);
        } catch (Throwable $exception) {
            $failed = $repository->find($execution->id);

            $this->displayExecution($failed, $repository->steps($failed));

            $this->components->error('A step failed: '.$exception->getMessage());
            $this->components->info(
                "The failure is persisted. Recover it with: laraflow:execute {$execution->id}"
            );

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        $this->components->info(
            "Done. Inspect it anytime with: laraflow:show {$execution->id}"
        );

        return self::SUCCESS;
    }
}
