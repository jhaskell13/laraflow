<?php

declare(strict_types=1);

namespace Laraflow\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container as AppContainer;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowResolver;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes one persisted workflow execution on a queue worker.
 *
 * The payload carries only the execution id. The workflow class is read
 * from the execution record, so a queued job can never disagree with
 * durable state about what it is supposed to run.
 *
 * Delivery is at-least-once. Duplicate deliveries are resolved by
 * claiming: exactly one worker claims the execution and the others
 * return without doing anything, so a duplicate is a no-op rather than a
 * failed job. See docs/queue-and-recovery.md.
 */
class ExecuteWorkflowJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Queue attempts for this job. Defaults to 1: Laraflow does not retry
     * a failed execution through the queue, because queue retries and
     * Laraflow's own step attempts would otherwise multiply. Re-running is
     * an explicit operation (laraflow:execute, laraflow:recover).
     */
    public int $tries = 1;

    public int $timeout = 60;

    public int $backoff = 0;

    public function __construct(
        public readonly int|string $executionId,
    ) {
        $this->applyQueueSettings();
    }

    public function handle(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowResolver $resolver,
        LoggerInterface $logger,
    ): void {
        try {
            $execution = $repository->find($this->executionId);
        } catch (ExecutionNotFoundException) {
            // The record was pruned or never committed. There is nothing
            // to run and nothing to repair by retrying.
            $logger->warning('Laraflow skipped a queued execution that no longer exists.', [
                'execution_id' => $this->executionId,
            ]);

            return;
        }

        // A missing or invalid class throws WorkflowDefinitionMismatchException,
        // which deliberately fails the job: that is a deployment problem an
        // operator must see, not a condition to retry through.
        $workflow = $resolver->resolve($execution->id, $execution->workflow);

        try {
            $runner->execute($execution->id, $workflow);
        } catch (ExecutionAlreadyClaimedException) {
            // A duplicate delivery, or a scheduled recovery racing the
            // original worker. The holder of the claim is running it.
            $logger->info('Laraflow ignored a duplicate delivery for a claimed execution.', [
                'execution_id' => $execution->id,
            ]);
        } catch (ExecutionNotRunnableException) {
            $logger->info('Laraflow ignored a delivery for an execution that is already complete.', [
                'execution_id' => $execution->id,
            ]);
        } catch (ClaimLostException) {
            // This worker was treated as dead and another took the
            // execution over mid-run; nothing of ours was persisted.
            $logger->warning('Laraflow lost an execution claim to another worker.', [
                'execution_id' => $execution->id,
                'hint' => 'Consider raising laraflow.execution.stale_after above your longest step.',
            ]);
        }

        // A step exception is left to propagate: the runner has already
        // persisted the failure, and propagating is what surfaces it to
        // Laravel's failed-job tooling.
    }

    /**
     * Laraflow has already persisted the step and execution failure by the
     * time a job fails. This hook only correlates the two for operators;
     * it deliberately does not write execution state, because a job killed
     * by timeout or signal never reaches it and must be recovered through
     * the stale-claim path instead.
     */
    public function failed(?Throwable $exception): void
    {
        $container = AppContainer::getInstance();

        if (! $container->bound(LoggerInterface::class)) {
            return;
        }

        $logger = $container->make(LoggerInterface::class);

        $logger->error('A Laraflow execution job failed.', [
            'execution_id' => $this->executionId,
            'exception' => $exception === null
                ? null
                : $exception::class.': '.$exception->getMessage(),
        ]);
    }

    /**
     * Laravel reads `tries` and `timeout` from the job's properties when
     * it builds the queue payload, so they must be set before dispatch; a
     * tries() method would be silently ignored. Reading them here means
     * direct dispatch honours laraflow.queue config too, not only
     * dispatch through the WorkflowDispatcher.
     */
    protected function applyQueueSettings(): void
    {
        $container = AppContainer::getInstance();

        if (! $container->bound(Config::class)) {
            return;
        }

        $config = $container->make(Config::class);

        $this->tries = (int) $config->get('laraflow.queue.tries', $this->tries);
        $this->timeout = (int) $config->get('laraflow.queue.timeout', $this->timeout);
        $this->backoff = (int) $config->get('laraflow.queue.backoff', $this->backoff);
    }
}
