<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * An execution was pushed onto the queue.
 *
 * Fired by the WorkflowDispatcher after the job is dispatched. Dispatch is
 * at-least-once: duplicate deliveries are resolved later by claiming, so
 * one execution can be dispatched more than once.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context (see docs/execution-lifecycle.md).
 */
readonly class WorkflowExecutionDispatched
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string|null  $connection  Queue connection, or null for the application default.
     * @param  string|null  $queue  Queue name, or null for the connection default.
     * @param  string|null  $correlationId  Application-supplied identifier
     *                                      recorded on the execution, or null if none was supplied.
     * @param  string|null  $tenantId  Application-supplied tenant identifier,
     *                                 or null if none was supplied.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public ?string $connection = null,
        public ?string $queue = null,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
