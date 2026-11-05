<?php

declare(strict_types=1);

namespace Laraflow\Observability\Tracing;

use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @experimental See Tracer.
 *
 * The tracer that ships: nothing is traced until a bridge binds one.
 */
final class NullTracer implements Tracer
{
    public function capture(): ?string
    {
        return null;
    }

    public function runStarted(WorkflowExecution $execution): void {}

    public function stepStarted(WorkflowExecution $execution, StepExecution $step): void {}

    public function runEnded(): void {}

    public function connectorCallStarted(Connection $connection, ConnectorRequest $request): void {}

    public function connectorCallEnded(): void {}
}
