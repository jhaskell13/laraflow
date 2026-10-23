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
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowExecution;
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
        WorkflowDispatcher $dispatcher,
        Config $config,
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
            $result = $runner->execute($execution->id, $workflow);

            if ($result->isRetrying() && $result->nextRetryAt !== null) {
                // A retry was durably scheduled; nothing failed. Hand the
                // due time to the queue where that is enabled and safe.
                $this->scheduleRetryDelivery($result, $dispatcher, $config, $logger);
            }
        } catch (RetryNotDueException) {
            // A delivery raced the schedule: a duplicate, an imprecise
            // driver, or an operator who already ran it. The schedule
            // stands and this delivery has nothing to do.
            $logger->info('Laraflow ignored a delivery for a retry that is not due yet.', [
                'execution_id' => $execution->id,
            ]);
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

        $message = null;

        if ($exception !== null) {
            $message = $exception->getMessage();

            // The configured redactor governs every sink Laraflow writes
            // to; this log line is no exception. The job may fail with a
            // step's exception, and step exceptions can carry secrets.
            if ($container->bound(ErrorRedactor::class)) {
                $message = $container->make(ErrorRedactor::class)->redact($exception, $message);
            }

            $message = $exception::class.': '.$message;
        }

        $logger->error('A Laraflow execution job failed.', [
            'execution_id' => $this->executionId,
            'exception' => $message,
        ]);
    }

    /**
     * Queue a delayed delivery for a scheduled retry — the low-latency
     * path, never the guarantee. The laraflow:recover scan remains the
     * contractual pickup: it needs no queue and catches anything this
     * loses, which is why every refusal here is a log line rather than
     * an error.
     *
     * Skipped on a sync connection: the sync driver ignores delay() and
     * runs the job inside the dispatch call, which would turn backoff
     * into immediate recursion — the whole budget burned in one stack,
     * with no backoff at all.
     */
    protected function scheduleRetryDelivery(
        WorkflowExecution $execution,
        WorkflowDispatcher $dispatcher,
        Config $config,
        LoggerInterface $logger,
    ): void {
        if (! (bool) $config->get('laraflow.retry.dispatch_delayed', true)) {
            return;
        }

        $connection = $this->resolveConnection($config);

        if ($config->get("queue.connections.{$connection}.driver") === 'sync') {
            $logger->notice(
                'Laraflow left a scheduled retry to laraflow:recover: the sync queue driver cannot delay it.',
                [
                    'execution_id' => $execution->id,
                    'next_retry_at' => $execution->nextRetryAt?->format('c'),
                ],
            );

            return;
        }

        try {
            $dispatcher->dispatchExecution(
                $execution->id,
                $this->connection,
                $this->queue,
                $execution->nextRetryAt,
            );
        } catch (Throwable $dispatchFailure) {
            // The retry is durably scheduled either way; losing the fast
            // path must not fail this job (the execution did not fail).
            $logger->warning('Laraflow could not queue a delayed retry; laraflow:recover will deliver it.', [
                'execution_id' => $execution->id,
                'exception' => $dispatchFailure::class.': '.$dispatchFailure->getMessage(),
            ]);
        }
    }

    /**
     * The connection this retry would be pushed onto, for the sync-driver
     * check: the job's own, else laraflow.queue.connection, else the
     * application default.
     */
    protected function resolveConnection(Config $config): string
    {
        foreach ([$this->connection, $config->get('laraflow.queue.connection')] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return (string) $config->get('queue.default');
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
