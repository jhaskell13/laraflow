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
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Exceptions\Workflow\TenantThrottledException;
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

    /**
     * The failure an operator queued this delivery to re-run, as its
     * `failed_at` (Y-m-d H:i:s) — set only by an operator's action
     * (laraflow:execute --queue, the dashboard's execute action, both
     * through Handoff). Null for every other delivery, so a duplicate or
     * late delivery finding the execution failed is a no-op, as for
     * completed: re-running a terminal failure is an explicit action,
     * never a broker's at-least-once side effect.
     *
     * An instant rather than a flag: a duplicate of the operator's own
     * job, arriving after the re-run it asked for has failed *again*,
     * must not take that new failure as asked-for too. The opt-in names
     * one failure and spends with it.
     */
    public function __construct(
        public readonly int $executionId,
        public readonly ?string $reexecuteFailedAt = null,
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
            $result = $runner->execute($execution->id, $workflow, reexecuteFailed: $this->reexecutes($execution));

            if (($result->isRetrying() || $result->isWaiting()) && $result->nextRetryAt !== null) {
                // A retry was durably scheduled, or a wait parked with a
                // known next instant (a timed resume, a signal deadline);
                // nothing failed. Hand the due time to the queue where
                // that is enabled and safe. An indefinite signal wait has
                // no instant to deliver — the signaller re-dispatches.
                $this->scheduleRetryDelivery($result, $dispatcher, $config, $logger);
            }
        } catch (TenantThrottledException $deferred) {
            // A deferral, not a queue retry: the job returns normally,
            // tries is untouched, and the queue gains no retry authority.
            // The execution is re-pushed for the instant the record says.
            $this->deferDelivery($deferred, $dispatcher, $config, $logger);
        } catch (ExecutionStillWaitingException) {
            // A delivery raced a park: a heartbeat landed after a signal
            // already resumed and re-parked the execution, or a delayed
            // delivery outlived the wait it was scheduled for. The park
            // stands and this delivery has nothing to do.
            $logger->info('Laraflow ignored a delivery for an execution that is parked on a wait.', [
                'execution_id' => $execution->id,
            ]);
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
            // Failed is here too unless this delivery is an operator's
            // queued re-run: a duplicate of the delivery that failed it
            // must not run it again.
            $logger->info('Laraflow ignored a delivery for an execution that is already finished (completed, cancelled, or failed).', [
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
     * Whether this delivery is the operator's re-run of the execution's
     * current failure. The claim still decides; a failure this delivery
     * was not issued for is refused there as not runnable.
     */
    protected function reexecutes(WorkflowExecution $execution): bool
    {
        return $this->reexecuteFailedAt !== null
            && $execution->isFailed()
            && $execution->failedAt?->format('Y-m-d H:i:s') === $this->reexecuteFailedAt;
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
     * The tenant is at a ceiling: push this execution again for the
     * instant the deferral names. On the sync driver a delayed push
     * would run inline at once — the refusal propagates to the
     * dispatcher's caller instead, as the contract says.
     */
    protected function deferDelivery(
        TenantThrottledException $deferred,
        WorkflowDispatcher $dispatcher,
        Config $config,
        LoggerInterface $logger,
    ): void {
        $connection = $this->resolveConnection($config);

        if ($config->get("queue.connections.{$connection}.driver") === 'sync') {
            throw $deferred;
        }

        try {
            $dispatcher->dispatchExecution(
                $deferred->executionId,
                $this->connection,
                $this->queue,
                $deferred->deferredUntil,
                // A deferred operator re-run is still one: the row stays
                // failed through a deferral, and the re-push must carry
                // the operator's opt-in or it would be refused on arrival.
                $this->reexecuteFailedAt !== null,
            );

            $logger->info('Laraflow re-queued a deferred execution for when its tenant may have a slot.', [
                'execution_id' => $deferred->executionId,
                'tenant_id' => $deferred->tenantId,
                'deferred_until' => $deferred->deferredUntil->format('c'),
            ]);
        } catch (Throwable $pushFailure) {
            // The deferral is recorded; the pending scan picks the
            // execution up once deferred_until passes, where it is
            // enabled, and laraflow:execute is always there.
            $logger->warning('Laraflow could not re-queue a deferred execution; laraflow:recover will deliver it.', [
                'execution_id' => $deferred->executionId,
                'exception' => $pushFailure::class.': '.$pushFailure->getMessage(),
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

        $this->tries = $this->intSetting($config, 'laraflow.queue.tries', $this->tries);
        $this->timeout = $this->intSetting($config, 'laraflow.queue.timeout', $this->timeout);
        $this->backoff = $this->intSetting($config, 'laraflow.queue.backoff', $this->backoff);
    }

    /**
     * Read an integer setting, treating blank as unset — the same rule
     * the service provider applies everywhere else. A stray empty env
     * line reaches config as '', and a blind (int) cast turns it into 0,
     * which for $tries means *unlimited* queue retries: the exact
     * authority this package promises the queue never has by default.
     */
    protected function intSetting(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return $value === '' || $value === null ? $default : (int) $value;
    }
}
