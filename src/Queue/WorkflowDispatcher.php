<?php

declare(strict_types=1);

namespace Laraflow\Queue;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionDispatched;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;

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
    public function __construct(
        protected WorkflowRunner $runner,
        protected ExecutionRepository $repository,
        protected Bus $bus,
        protected Config $config,
        protected ?EventEmitter $events = null,
    ) {}

    /**
     * Create an execution and queue it. Nothing runs in this process.
     *
     * @param  array<string, mixed>  $input
     * @param  string|null  $connection  Overrides laraflow.queue.connection.
     * @param  string|null  $queue  Overrides laraflow.queue.queue.
     *
     * @throws InvalidWorkflowDefinitionException if the workflow has no steps or a step is not a Step class.
     * @throws UnserializableValueException if the input is not JSON-serializable.
     */
    public function dispatch(
        Workflow $workflow,
        array $input = [],
        ?string $connection = null,
        ?string $queue = null,
    ): WorkflowExecution {
        $execution = $this->runner->start($workflow, $input);

        $this->queueJob($execution, $connection, $queue);

        return $execution;
    }

    /**
     * Queue an execution that already exists: a failed one to retry, or an
     * interrupted one to recover.
     *
     * A `running` execution is accepted on purpose — recovering a worker
     * that died leaves exactly that state, and the claim decides which
     * worker actually proceeds. Only a completed execution is refused,
     * because there is nothing left to run.
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotRunnableException when the execution is already completed.
     */
    public function dispatchExecution(
        int|string $executionId,
        ?string $connection = null,
        ?string $queue = null,
    ): WorkflowExecution {
        $execution = $this->repository->find($executionId);

        if ($execution->isCompleted()) {
            throw ExecutionNotRunnableException::forStatus($execution->id, $execution->status);
        }

        $this->queueJob($execution, $connection, $queue);

        return $execution;
    }

    protected function queueJob(
        WorkflowExecution $execution,
        ?string $connection,
        ?string $queue,
    ): void {
        $connection ??= $this->stringConfig('laraflow.queue.connection');
        $queue ??= $this->stringConfig('laraflow.queue.queue');

        $job = new ExecuteWorkflowJob($execution->id);

        // Null leaves Laravel's own defaults in place.
        $job->onConnection($connection);
        $job->onQueue($queue);

        if ((bool) $this->config->get('laraflow.queue.after_commit', true)) {
            // Without this, a worker can receive an execution id before
            // the surrounding application transaction has committed.
            $job->afterCommit();
        }

        $this->bus->dispatch($job);

        $this->events?->emit(new WorkflowExecutionDispatched(
            executionId: $execution->id,
            workflow: $execution->workflow,
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
