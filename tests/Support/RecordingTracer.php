<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Observability\Tracing\Tracer;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;
use RuntimeException;

/**
 * Records every hook the core calls, in order, as short strings a test
 * can compare whole.
 */
final class RecordingTracer implements Tracer
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        public ?string $context = null,
        public bool $throws = false,
    ) {}

    public function capture(): ?string
    {
        $this->fail();

        return $this->context;
    }

    public function runStarted(WorkflowExecution $execution): void
    {
        $this->fail();
        $this->calls[] = 'run:'.class_basename($execution->workflow);
    }

    public function stepStarted(WorkflowExecution $execution, StepExecution $step): void
    {
        $this->fail();
        $this->calls[] = 'step:'.class_basename($step->step).'#'.$step->attempts;
    }

    public function runEnded(): void
    {
        $this->fail();
        $this->calls[] = 'end';
    }

    public function connectorCallStarted(Connection $connection, ConnectorRequest $request): void
    {
        $this->fail();
        $this->calls[] = 'call:'.strtoupper($request->method).' '.$connection->connector.' '.$request->path();
    }

    public function connectorCallEnded(): void
    {
        $this->fail();
        $this->calls[] = 'called';
    }

    private function fail(): void
    {
        if ($this->throws) {
            throw new RuntimeException('the tracer broke');
        }
    }
}
