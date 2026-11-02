<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @internal The one way a console command creates an execution and runs
 * or dispatches it, shared by `laraflow:start` and `laraflow:demo`.
 *
 * The outcome reporting itself lives in DisplaysExecutions, with the
 * commands that run an execution they did not create.
 *
 * @mixin \Illuminate\Console\Command
 */
trait StartsExecutions
{
    use DisplaysExecutions;

    /**
     * Create the execution and run it in this process.
     *
     * The failure of a step is a command failure here, because the
     * caller asked for the run and did not get it; the persisted record
     * and the command that recovers it are printed with the error.
     *
     * @param  array<string, mixed>  $input
     */
    protected function startInline(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        Workflow $workflow,
        array $input,
        ?ExecutionOptions $options = null,
    ): int {
        $execution = $runner->start($workflow, $input, $options);

        try {
            $execution = $runner->execute($execution->id, $workflow);
        } catch (WorkflowException $exception) {
            // Not a step failure: the runtime refused the run itself —
            // another worker holds the claim, the claim was lost, the
            // definition no longer matches. The message names the thing
            // and the fix, so it is printed as it stands, as every other
            // command prints a WorkflowException.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->reportFailedRun($repository, $execution->id, $exception);

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        return $this->reportOutcome($execution);
    }

    /**
     * Create the execution and hand it to the queue.
     *
     * start() + dispatchExecution() rather than dispatch(), so the
     * execution id is known even if the job throws — a sync queue
     * connection runs the job inside the dispatch call itself.
     *
     * @param  array<string, mixed>  $input
     */
    protected function startQueued(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowDispatcher $dispatcher,
        Workflow $workflow,
        array $input,
        ?ExecutionOptions $options = null,
    ): int {
        $execution = $runner->start($workflow, $input, $options);

        try {
            $dispatcher->dispatchExecution($execution->id);
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());
            $this->components->info(
                "Execution [{$execution->id}] was created and not queued. "
                ."Run it with: laraflow:execute {$execution->id}"
            );

            return self::FAILURE;
        } catch (Throwable $exception) {
            // Did the job run, or did the push fail? The configured
            // driver cannot answer: a broker refusing a connection
            // while the connection is nominally sync would be reported
            // as a step failure with every step still pending, and a
            // listener throwing after a successful push would be
            // reported as a failure to queue. The record answers it.
            // Nothing reached a step while the execution is still
            // pending, whatever threw.
            if ($repository->find($execution->id)->isPending()) {
                $this->components->error('The execution could not be queued: '.$exception->getMessage());
                // This command is the only thing that knows the id it
                // just wrote, and the scan that would otherwise find a
                // never-dispatched execution is off by default
                // (docs/queue-and-recovery.md).
                $this->components->info(
                    "Execution [{$execution->id}] is persisted and pending. "
                    ."Run it here with: laraflow:execute {$execution->id}"
                );

                return self::FAILURE;
            }

            $this->reportFailedRun(
                $repository,
                $execution->id,
                $exception,
                'The queue connection ran the job in this process (sync driver) and a step failed: ',
            );

            return self::FAILURE;
        }

        // The sync driver runs the job inside the dispatch call, so by
        // here the execution may already be finished — or parked, or
        // retrying, since the job deliberately does not throw for those.
        // Telling the user to start a worker for work that has already
        // run is how the result goes unseen, so what actually happened
        // is read back and reported.
        $execution = $repository->find($execution->id);

        if (! $execution->isPending()) {
            $this->components->info(
                'The queue connection ran the job in this process (sync driver).'
            );

            $this->displayExecution($execution, $repository->steps($execution));

            return $this->reportOutcome($execution);
        }

        $this->components->info(
            "Queued as execution {$execution->id}. Process it with: php artisan queue:work"
        );
        $this->components->info(
            "Inspect it anytime with: laraflow:show {$execution->id}"
        );

        return self::SUCCESS;
    }
}
