<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Executes (or re-executes) a persisted execution using the workflow class
 * recorded on it: pending executions run for the first time, failed ones
 * retry from their last checkpoint, stale running ones are reclaimed.
 */
class ExecuteCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:execute
        {execution : The id of the execution to run or recover}';

    protected $description = 'Execute or recover a persisted workflow execution';

    public function handle(WorkflowRunner $runner, ExecutionRepository $repository): int
    {
        $id = (string) $this->argument('execution');

        try {
            $execution = $repository->find($id);
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $class = $execution->workflow;

        if (! class_exists($class) || ! is_subclass_of($class, Workflow::class)) {
            $this->components->error(
                "Execution [{$id}] was created from [{$class}], which no longer exists "
                .'or is not a workflow. It cannot be executed by this code base.'
            );

            return self::FAILURE;
        }

        /** @var Workflow $workflow */
        $workflow = $this->laravel->make($class);

        $this->components->info("Executing [{$id}] with [{$class}]. Completed steps will be skipped.");

        try {
            $execution = $runner->execute($execution->id, $workflow);
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $failed = $repository->find($id);

            $this->displayExecution($failed, $repository->steps($failed));

            $this->components->error('A step failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        $this->components->info('Execution completed.');

        return self::SUCCESS;
    }
}
