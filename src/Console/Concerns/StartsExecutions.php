<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @internal The one way a console command starts a workflow and
 * reports what became of it, shared by `laraflow:start` and the demo:
 * inline, the execution is created and run here and every non-terminal
 * outcome — retrying, waiting, cancelled — is said for what it is
 * rather than reported as done; queued, it is created first and
 * dispatched second, so the id is known even when a sync queue
 * connection runs the job inside the dispatch call and a step fails.
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
        } catch (Throwable $exception) {
            // Any driver but sync throwing here is the push itself
            // failing — a different subsystem, said plainly.
            if (! $this->queueDriverIsSync()) {
                $this->components->error('The execution could not be queued: '.$exception->getMessage());

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

        $this->components->info(
            "Queued as execution {$execution->id}. Process it with: php artisan queue:work"
        );
        $this->components->info(
            "Inspect it anytime with: laraflow:show {$execution->id}"
        );

        return self::SUCCESS;
    }

    /**
     * Say what a finished run amounts to. Three of the four outcomes
     * are not failures and not completions either: the step's policy,
     * a wait, or an operator owns what happens next, so the command
     * succeeds and says who.
     */
    protected function reportOutcome(WorkflowExecution $execution): int
    {
        if ($execution->isRetrying()) {
            $this->components->warn(sprintf(
                'A step failed and its retry policy scheduled another attempt for %s. '
                .'Deliver it with: laraflow:recover --sync — or skip the wait with: laraflow:execute %s --now',
                $this->formatDate($execution->nextRetryAt),
                $execution->id,
            ));

            return self::SUCCESS;
        }

        if ($execution->isWaiting()) {
            $this->components->warn(sprintf(
                'A step parked the execution on a wait. See what it is waiting for with: laraflow:show %s '
                .'— a timed wait is delivered by laraflow:recover, a signal by WorkflowRunner::signal().',
                $execution->id,
            ));

            return self::SUCCESS;
        }

        if ($execution->isCancelled()) {
            $this->components->info('The execution was cancelled during the run; completed steps are kept.');

            return self::SUCCESS;
        }

        $this->components->info(
            "Done. Inspect it anytime with: laraflow:show {$execution->id}"
        );

        return self::SUCCESS;
    }

    /**
     * A step threw and the failure is persisted: show the record as it
     * now stands, the error, and the command that picks it up again.
     */
    protected function reportFailedRun(
        ExecutionRepository $repository,
        int $executionId,
        Throwable $exception,
        string $prefix = 'A step failed: ',
    ): void {
        $failed = $repository->find($executionId);

        $this->displayExecution($failed, $repository->steps($failed));

        $this->components->error($prefix.$exception->getMessage());
        $this->components->info(
            "The failure is persisted. Recover it with: laraflow:execute {$executionId}"
        );
    }
}
