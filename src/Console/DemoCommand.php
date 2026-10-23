<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Demo\DemoWorkflow;
use Laraflow\Queue\WorkflowDispatcher;
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
            // start() + dispatchExecution() rather than dispatch(), so the
            // execution id is known even if the job throws — a sync queue
            // connection runs the job inside the dispatch call itself.
            $execution = $runner->start(new DemoWorkflow, $input);

            try {
                $dispatcher->dispatchExecution($execution->id);
            } catch (Throwable $exception) {
                $failed = $repository->find($execution->id);

                $this->displayExecution($failed, $repository->steps($failed));

                $this->components->error(
                    'The queue connection ran the job in this process (sync driver) '
                    .'and a step failed: '.$exception->getMessage()
                );
                $this->components->info(
                    "The failure is persisted. Recover it with: laraflow:execute {$execution->id}"
                );

                return self::FAILURE;
            }

            $this->components->info(
                "Queued as execution {$execution->id}. Process it with: php artisan queue:work"
            );
            $this->components->info(
                "Inspect it anytime with: laraflow:show {$execution->id}"
            );

            return self::SUCCESS;
        }

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
