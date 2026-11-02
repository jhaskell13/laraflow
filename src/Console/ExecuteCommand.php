<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Console\Concerns\StartsExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowResolver;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Executes (or re-executes) a persisted execution using the workflow class
 * recorded on it: pending executions run for the first time, failed ones
 * retry from their last checkpoint, stale running ones are reclaimed.
 * With --queue the execution is handed to a queue worker instead of
 * running in this process.
 */
class ExecuteCommand extends Command
{
    use ParsesExecutionId;
    use StartsExecutions;

    protected $signature = 'laraflow:execute
        {execution : The id of the execution to run or recover}
        {--queue : Dispatch the execution to the queue instead of running it in this process}
        {--now : Run a retrying execution, or resume a timed wait, immediately — ignoring its schedule}';

    protected $description = 'Execute or recover a persisted workflow execution';

    public function handle(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowResolver $resolver,
        WorkflowDispatcher $dispatcher,
    ): int {
        try {
            $id = $this->executionIdArgument();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $execution = $repository->find($id);
            // Resolve up front in both modes: a missing workflow class
            // should fail here, not minutes later on a queue worker.
            $workflow = $resolver->resolve($execution->id, $execution->workflow);
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('queue')) {
            return $this->reportHandoff(
                Handoff::attempt($dispatcher, $repository, $execution),
                $repository,
            );
        }

        $this->components->info(
            'Executing ['.$id.'] with ['.$execution->workflow.']. Completed steps will be skipped.'
        );

        try {
            $execution = $runner->execute(
                $execution->id,
                $workflow,
                ignoreRetrySchedule: (bool) $this->option('now'),
            );
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->reportFailedRun($repository, $id, $exception);

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        // Every outcome — retrying, waiting, cancelled, completed — is
        // said by the one reporter every running command shares.
        return $this->reportOutcome($execution);
    }
}
