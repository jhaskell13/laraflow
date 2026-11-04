<?php

declare(strict_types=1);

namespace Laraflow\Queue;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseTransactionsManager;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionDispatched;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Support\Clock;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;

/**
 * Starts workflow executions asynchronously.
 *
 * This is the queue counterpart of WorkflowRunner::run(): where run()
 * creates an execution and runs it in the current process, dispatch()
 * creates it and hands it to a queue worker, returning the pending
 * handle immediately.
 *
 * Queue concerns live here rather than on WorkflowRunner so the runner
 * stays a pure durable-execution engine with no queue dependency.
 */
final class WorkflowDispatcher
{
    protected ClockInterface $clock;

    public function __construct(
        protected WorkflowRunner $runner,
        protected ExecutionRepository $repository,
        protected Bus $bus,
        protected Config $config,
        protected EventEmitter $events,
        ClockInterface $clock,
        protected DatabaseTransactionsManager $transactions,
    ) {
        $this->clock = $clock;
    }

    /**
     * Create an execution and queue it. Nothing runs in this process.
     *
     * @param  array<string, mixed>  $input
     * @param  string|null  $connection  Overrides laraflow.queue.connection.
     * @param  string|null  $queue  Overrides laraflow.queue.queue.
     * @param  ExecutionOptions|null  $options  Correlation and tenant
     *                                          identifiers to record on the execution.
     *
     * @throws InvalidWorkflowDefinitionException if the workflow has no steps or a step is not a Step class.
     * @throws UnserializableValueException if the input is not JSON-serializable.
     */
    public function dispatch(
        Workflow $workflow,
        array $input = [],
        ?string $connection = null,
        ?string $queue = null,
        ?ExecutionOptions $options = null,
    ): WorkflowExecution {
        $execution = $this->runner->start($workflow, $input, $options);

        $this->queueJob($execution, $connection, $queue);

        return $execution;
    }

    /**
     * Queue an execution that already exists: a failed one to retry, or an
     * interrupted one to recover.
     *
     * A `running` execution is accepted on purpose — recovering a worker
     * that died leaves exactly that state, and the claim decides which
     * worker actually proceeds. Terminal executions (completed,
     * cancelled) are refused, because there is nothing left to run.
     *
     * @param  DateTimeInterface|null  $availableAt  Hold the delivery until
     *                                               this time — how a scheduled retry travels on the queue. The
     *                                               claim's schedule gate still decides, so a delivery arriving
     *                                               early (an imprecise driver, a duplicate) is refused with
     *                                               RetryNotDueException and treated as a no-op by the job; the
     *                                               queue owes no precision here.
     * @param  bool  $reexecuteFailed  The operator's opt-in to re-running a
     *                                 `failed` execution, carried on the job to the claim. Without
     *                                 it a failed execution may still be queued, but the delivery
     *                                 is refused as not runnable and does nothing.
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotRunnableException when the execution is already completed.
     */
    public function dispatchExecution(
        int $executionId,
        ?string $connection = null,
        ?string $queue = null,
        ?DateTimeInterface $availableAt = null,
        bool $reexecuteFailed = false,
    ): WorkflowExecution {
        $execution = $this->repository->find($executionId);

        if ($execution->isCompleted() || $execution->isCancelled()) {
            throw ExecutionNotRunnableException::forStatus($execution->id, $execution->status);
        }

        $this->queueJob($execution, $connection, $queue, $availableAt, $reexecuteFailed);

        return $execution;
    }

    protected function queueJob(
        WorkflowExecution $execution,
        ?string $connection,
        ?string $queue,
        ?DateTimeInterface $availableAt = null,
        bool $reexecuteFailed = false,
    ): void {
        $connection ??= $this->stringConfig('laraflow.queue.connection');
        $queue ??= $this->stringConfig('laraflow.queue.queue');

        // The opt-in names the failure the caller saw, so the job cannot
        // carry it on to a later one (see ExecuteWorkflowJob).
        $job = new ExecuteWorkflowJob(
            $execution->id,
            $reexecuteFailed && $execution->isFailed() ? $execution->failedAt?->format('Y-m-d H:i:s') : null,
        );

        // Null leaves Laravel's own defaults in place.
        $job->onConnection($connection);
        $job->onQueue($queue);

        if ($availableAt !== null) {
            $job->delay($availableAt);
        }

        if ((bool) $this->config->get('laraflow.queue.after_commit', true)) {
            // Without this, a worker can receive an execution id before
            // the surrounding application transaction has committed.
            $job->afterCommit();
        }

        // Recorded *before* the push: on the sync driver the job runs
        // inside dispatch(), and the claim it makes must already see when
        // the work became available or it cannot measure its own wait.
        $dispatchedAt = $this->clock->now();

        // Normalised to the clock's timezone, so every instant on the
        // record reads in one zone; the column holds UTC either way.
        $this->repository->recordDispatch(
            $execution->id,
            $dispatchedAt,
            $availableAt !== null
                ? DateTimeImmutable::createFromInterface($availableAt)->setTimezone($dispatchedAt->getTimezone())
                : null,
        );

        $this->bus->dispatch($job);

        $event = new WorkflowExecutionDispatched(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            connection: $connection,
            queue: $queue,
        );

        // The event describes a push. When the push is deferred to the
        // surrounding transaction's commit, so is the event — registered
        // with the same manager and after the push's own callback, so on
        // commit it fires once the job is genuinely on the queue, and on
        // rollback it never fires, exactly as the job never goes out.
        // Outside a transaction the manager runs the callback at once.
        if ($job->afterCommit === true) {
            $this->transactions->addCallback(fn () => $this->events->emit($event));

            return;
        }

        $this->events->emit($event);
    }

    protected function stringConfig(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
