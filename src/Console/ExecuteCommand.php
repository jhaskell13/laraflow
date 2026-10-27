<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
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
    use DisplaysExecutions;

    protected $signature = 'laraflow:execute
        {execution : The id of the execution to run or recover}
        {--queue : Dispatch the execution to the queue instead of running it in this process}
        {--now : Run a retrying execution immediately, ignoring its next_retry_at schedule}';

    protected $description = 'Execute or recover a persisted workflow execution';

    public function handle(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowResolver $resolver,
        WorkflowDispatcher $dispatcher,
    ): int {
        $id = (string) $this->argument('execution');

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
            try {
                $dispatcher->dispatchExecution($execution->id);
            } catch (WorkflowException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            } catch (Throwable $exception) {
                // A sync queue connection runs the job inside the dispatch
                // call, so a step failure surfaces here instead of on a worker.
                $failed = $repository->find($id);

                $this->displayExecution($failed, $repository->steps($failed));

                $this->components->error(
                    'The queue connection ran the job in this process (sync driver) '
                    .'and a step failed: '.$exception->getMessage()
                );

                return self::FAILURE;
            }

            $this->components->info(
                "Execution [{$id}] queued. A queue worker will pick it up; "
                ."inspect progress with: laraflow:show {$id}"
            );

            return self::SUCCESS;
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
            $failed = $repository->find($id);

            $this->displayExecution($failed, $repository->steps($failed));

            $this->components->error('A step failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        if ($execution->isRetrying()) {
            // Not an operator failure: the step's policy owns what
            // happens next, so the command succeeds and says when.
            $this->components->warn(
                'A step failed and its retry policy scheduled another attempt for '
                .$this->formatDate($execution->nextRetryAt)
                .". Run it early with: laraflow:execute {$id} --now"
            );

            return self::SUCCESS;
        }

        if ($execution->isCancelled()) {
            $this->components->info('Execution was cancelled during the run; completed steps are kept.');

            return self::SUCCESS;
        }

        $this->components->info('Execution completed.');

        return self::SUCCESS;
    }
}
