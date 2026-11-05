<?php

declare(strict_types=1);

namespace Laraflow\Observability\Tracing;

use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @experimental The tracing hook is settled by its first implementation
 * (laraflow/opentelemetry); see docs/tracing.md.
 *
 * Where the core reports the boundaries a trace is built from: a run, a
 * step attempt's start, a connector call, and the trace context to
 * remember with an execution created now. The ends of step attempts and
 * the outcome of a run arrive as the lifecycle events the runner
 * already fires; a tracer listens to those for them.
 *
 * Called outside every durable decision, through a guard that logs and
 * swallows a throwing tracer: tracing must never affect an execution.
 * The binding that ships, NullTracer, does nothing.
 */
interface Tracer
{
    /**
     * The trace context active now, as a W3C `traceparent`, to be stored
     * with an execution created now; null when there is none.
     */
    public function capture(): ?string;

    /**
     * A process claimed $execution and is about to run it. Runs nest: a
     * sub-workflow run inline begins inside its parent's step.
     */
    public function runStarted(WorkflowExecution $execution): void;

    /** One attempt of one step of the current run begins. */
    public function stepStarted(WorkflowExecution $execution, StepExecution $step): void;

    /**
     * The innermost run ended — completed, failed, parked, scheduled
     * for a retry, or released by an exception. Anything it left open
     * ends with it.
     */
    public function runEnded(): void;

    /**
     * A connector is about to send. Only the method and the path —
     * never the query string, headers, or body — may be recorded.
     */
    public function connectorCallStarted(Connection $connection, ConnectorRequest $request): void;

    /** The connector call ended; its event has already fired. */
    public function connectorCallEnded(): void;
}
