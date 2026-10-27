<?php

declare(strict_types=1);

namespace Laraflow\Queue;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
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
class WorkflowDispatcher
{
    protected ClockInterface $clock;

    public function __construct(
        protected WorkflowRunner $runner,
        protected ExecutionRepository $repository,
        protected Bus $bus,
        protected Config $config,
        protected ?EventEmitter $events = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new Clock;
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
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotRunnableException when the execution is already completed.
     */
    public function dispatchExecution(
        int|string $executionId,
        ?string $connection = null,
        ?string $queue = null,
        ?DateTimeInterface $availableAt = null,
    ): WorkflowExecution {
        $execution = $this->repository->find($executionId);

        if ($execution->isCompleted() || $execution->isCancelled()) {
            throw ExecutionNotRunnableException::forStatus($execution->id, $execution->status);
        }

        $this->queueJob($execution, $connection, $queue, $availableAt);

        return $execution;
    }

    protected function queueJob(
        WorkflowExecution $execution,
        ?string $connection,
        ?string $queue,
        ?DateTimeInterface $availableAt = null,
    ): void {
        $connection ??= $this->stringConfig('laraflow.queue.connection');
        $queue ??= $this->stringConfig('laraflow.queue.queue');

        $job = new ExecuteWorkflowJob($execution->id);

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

        $this->repository->recordDispatch(
            $execution->id,
            $dispatchedAt,
            $availableAt !== null ? DateTimeImmutable::createFromInterface($availableAt) : null,
        );

        $this->bus->dispatch($job);

        $this->events?->emit(new WorkflowExecutionDispatched(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            connection: $connection,
            queue: $queue,
        ));
    }

    protected function stringConfig(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
