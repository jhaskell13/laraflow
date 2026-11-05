<?php

declare(strict_types=1);

namespace Laraflow\Observability\Tracing;

use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * The bound Tracer, called the way the event emitter calls listeners: a
 * tracer that throws is logged and ignored, never allowed to fail a
 * step, abort a run, or lose a connector response (docs/tracing.md).
 *
 * capture() is also where a captured context is checked: a tracer that
 * returns something that is not a valid `traceparent` stores nothing,
 * rather than failing the start of the execution it was asked about.
 */
final class GuardedTracer implements Tracer
{
    public function __construct(
        private readonly Tracer $tracer,
        private readonly LoggerInterface $logger,
    ) {}

    public function capture(): ?string
    {
        $context = $this->guard(fn (): ?string => $this->tracer->capture(), __FUNCTION__);

        return is_string($context) && ExecutionOptions::isTraceParent($context) ? $context : null;
    }

    public function runStarted(WorkflowExecution $execution): void
    {
        $this->guard(fn () => $this->tracer->runStarted($execution), __FUNCTION__);
    }

    public function stepStarted(WorkflowExecution $execution, StepExecution $step): void
    {
        $this->guard(fn () => $this->tracer->stepStarted($execution, $step), __FUNCTION__);
    }

    public function runEnded(): void
    {
        $this->guard(fn () => $this->tracer->runEnded(), __FUNCTION__);
    }

    public function connectorCallStarted(Connection $connection, #[SensitiveParameter] ConnectorRequest $request): void
    {
        $this->guard(fn () => $this->tracer->connectorCallStarted($connection, $request), __FUNCTION__);
    }

    public function connectorCallEnded(): void
    {
        $this->guard(fn () => $this->tracer->connectorCallEnded(), __FUNCTION__);
    }

    private function guard(callable $call, string $hook): mixed
    {
        try {
            return $call();
        } catch (Throwable $failure) {
            $this->logger->error('A Laraflow tracer threw; the execution continues untraced.', [
                'tracer' => $this->tracer::class,
                'hook' => $hook,
                'exception' => $failure::class,
            ]);

            return null;
        }
    }
}
