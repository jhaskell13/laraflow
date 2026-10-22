<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Workflow\ExecutionRepository;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 */
class ShowCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:show
        {execution : The id of the execution to inspect}';

    protected $description = 'Inspect a workflow execution and its steps';

    public function handle(ExecutionRepository $repository): int
    {
        try {
            $execution = $repository->find((string) $this->argument('execution'));
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        if ($execution->claimToken !== null) {
            $this->components->twoColumnDetail(
                '<fg=gray>Claimed at</>',
                $this->formatDate($execution->claimedAt).' (a worker owns or owned this execution)'
            );
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
