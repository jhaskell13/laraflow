<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Outcome;
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
    use ParsesExecutionId;

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
            try {
                $dispatcher->dispatchExecution($execution->id);
            } catch (WorkflowException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            } catch (Throwable $exception) {
                // A sync queue connection runs the job inside the dispatch
                // call, so a step failure surfaces here instead of on a
                // worker. Any other driver throwing here is the push
                // itself failing — a different subsystem, said plainly.
                if (! $this->queueDriverIsSync()) {
                    $this->components->error('The execution could not be queued: '.$exception->getMessage());

                    return self::FAILURE;
                }

                $this->reportFailedRun(
                    $repository,
                    $id,
                    $exception,
                    'The queue connection ran the job in this process (sync driver) and a step failed: ',
                );

                return self::FAILURE;
            }

            // A sync queue connection runs the job inside the dispatch
            // call, so by here it may already have run. "Still pending"
            // cannot say so — this command dispatches failed and
            // retrying executions too — so the record is compared with
            // the one read before the push: a run that completed changed
            // the status, and a run that failed again moved finished_at.
            $before = $execution;
            $execution = $repository->find($id);

            if (Outcome::ranInline($before, $execution)) {
                $this->components->info(
                    'The queue connection ran the job in this process (sync driver).'
                );

                $this->displayExecution($execution, $repository->steps($execution));

                return $this->reportOutcome($execution);
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
            $this->reportFailedRun($repository, $id, $exception);

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        // Every outcome — retrying, waiting, cancelled, completed — is
        // said by the one reporter every running command shares.
        return $this->reportOutcome($execution);
    }
}
