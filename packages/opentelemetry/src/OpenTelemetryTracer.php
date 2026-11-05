<?php

declare(strict_types=1);

namespace Laraflow\OpenTelemetry;

use DateTimeImmutable;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Events\WorkflowStepSkipped;
use Laraflow\Events\WorkflowStepWaiting;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Observability\Tracing\Tracer;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Laraflow's spans, as docs/tracing.md defines them, through the
 * OpenTelemetry API.
 *
 * The core reports where runs, step attempts, and connector calls
 * begin; the lifecycle events say how each ended, and record() reads
 * them. Every span this class opens it also activates, so a span the
 * application or another instrumentation starts inside a step — a
 * database query, an HTTP call — is that step's child. Scopes are
 * detached in the reverse order they were attached, as OpenTelemetry's
 * context storage requires: a connector call, then its step, then its
 * run.
 *
 * Bound as a singleton, and stateful only between a run's start and its
 * end: runEnded() closes everything the run opened, so nothing survives
 * into the next request or job of a long-lived worker.
 */
final class OpenTelemetryTracer implements Tracer
{
    /**
     * Open runs, innermost last.
     *
     * @var array<int, array{execution: WorkflowExecution, span: SpanInterface, scope: ScopeInterface, outcome: string, step: array{span: SpanInterface, scope: ScopeInterface, position: int, attempt: int}|null}>
     */
    private array $runs = [];

    /**
     * Open connector calls, innermost last.
     *
     * @var list<array{span: SpanInterface, scope: ScopeInterface}>
     */
    private array $calls = [];

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly ClockInterface $clock,
        private readonly int $linkAfterSeconds = 3600,
    ) {}

    public function capture(): ?string
    {
        if (! Span::getCurrent()->getContext()->isValid()) {
            return null;
        }

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        return $carrier['traceparent'] ?? null;
    }

    public function runStarted(WorkflowExecution $execution): void
    {
        $origin = $this->origin($execution);
        $active = Span::getCurrent()->getContext();

        $builder = $this->tracer->spanBuilder('laraflow.run '.$execution->workflow)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes($this->executionAttributes($execution) + ['laraflow.plan_version' => $execution->planVersion]);

        // docs/tracing.md, "Joining runs into one trace": the active span
        // in this process; else the origin while it is recent; else a
        // new trace. Linked to the origin whenever it is not the parent.
        if ($active->isValid()) {
            $builder->setParent(Context::getCurrent());
        } elseif ($origin !== null && $this->recent($execution)) {
            $builder->setParent($origin['context']);
        } else {
            $builder->setParent(false);
        }

        $parentIsOrigin = ! $active->isValid() && $origin !== null && $this->recent($execution);

        if ($origin !== null && ! $parentIsOrigin && ! $this->same($origin['span'], $active)) {
            $builder->addLink($origin['span']);
        }

        $span = $builder->startSpan();

        $this->runs[] = [
            'execution' => $execution,
            'span' => $span,
            'scope' => $span->activate(),
            'outcome' => 'released',
            'step' => null,
        ];
    }

    public function stepStarted(WorkflowExecution $execution, StepExecution $step): void
    {
        $index = array_key_last($this->runs);

        if ($index === null) {
            return;
        }

        // A step whose end no event reported — the runner moved on, or
        // threw past it — ends as interrupted before the next begins.
        $this->endStep($index, 'interrupted');

        $span = $this->tracer->spanBuilder('laraflow.step '.$step->step)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes($this->executionAttributes($execution) + [
                'laraflow.step' => $step->step,
                'laraflow.step.position' => $step->position,
                'laraflow.step.attempt' => $step->attempts,
            ])
            ->startSpan();

        $this->runs[$index]['step'] = [
            'span' => $span,
            'scope' => $span->activate(),
            'position' => $step->position,
            'attempt' => $step->attempts,
        ];
    }

    public function runEnded(): void
    {
        $index = array_key_last($this->runs);

        if ($index === null) {
            return;
        }

        // A call still open belongs to this run: close it first, so the
        // scopes come off in the order they went on.
        while ($this->calls !== []) {
            $this->connectorCallEnded();
        }

        $this->endStep($index, 'interrupted');

        $run = $this->runs[$index];
        unset($this->runs[$index]);
        $this->runs = array_values($this->runs);

        $run['span']->setAttribute('laraflow.run.outcome', $run['outcome']);
        $run['scope']->detach();
        $run['span']->end();
    }

    public function connectorCallStarted(Connection $connection, #[SensitiveParameter] ConnectorRequest $request): void
    {
        $method = strtoupper($request->method);
        $attributes = [
            'laraflow.connector' => $connection->connector,
            'laraflow.connection' => $connection->name,
            'http.request.method' => $method,
            // The path alone: a query string may carry a credential.
            'url.path' => $request->path(),
        ];

        $tenant = $this->current()?->tenantId;

        if ($tenant !== null) {
            $attributes['laraflow.tenant_id'] = $tenant;
        }

        $span = $this->tracer->spanBuilder($method.' '.$connection->connector)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes($attributes)
            ->startSpan();

        $this->calls[] = ['span' => $span, 'scope' => $span->activate()];
    }

    public function connectorCallEnded(): void
    {
        $call = array_pop($this->calls);

        if ($call === null) {
            return;
        }

        $call['scope']->detach();
        $call['span']->end();
    }

    /**
     * The end of a step attempt, the outcome of a run, the result of a
     * connector call — read from the events the core fires anyway.
     */
    public function record(object $event): void
    {
        match (true) {
            $event instanceof WorkflowStepCompleted => $this->stepEnded($event->executionId, $event->position, 'completed'),
            $event instanceof WorkflowStepSkipped => $this->stepEnded($event->executionId, $event->position, 'skipped'),
            $event instanceof WorkflowStepWaiting => $this->stepWaiting($event),
            $event instanceof WorkflowStepFailed => $this->stepFailed($event),
            $event instanceof WorkflowStepRetryScheduled => $this->outcome($event->executionId, 'retrying'),
            $event instanceof WorkflowExecutionCompleted => $this->outcome($event->executionId, 'completed'),
            $event instanceof WorkflowExecutionCancelled => $this->outcome($event->executionId, 'cancelled'),
            $event instanceof WorkflowExecutionFailed => $this->runFailed($event),
            $event instanceof ConnectorRequestSent => $this->callAnswered($event),
            $event instanceof ConnectorRequestFailed => $this->callFailed($event),
            default => null,
        };
    }

    /**
     * The events record() reads.
     *
     * @return list<class-string>
     */
    public static function events(): array
    {
        return [
            WorkflowStepCompleted::class,
            WorkflowStepSkipped::class,
            WorkflowStepWaiting::class,
            WorkflowStepFailed::class,
            WorkflowStepRetryScheduled::class,
            WorkflowExecutionCompleted::class,
            WorkflowExecutionCancelled::class,
            WorkflowExecutionFailed::class,
            ConnectorRequestSent::class,
            ConnectorRequestFailed::class,
        ];
    }

    private function stepWaiting(WorkflowStepWaiting $event): void
    {
        $this->stepEnded($event->executionId, $event->position, 'waiting');
        $this->outcome($event->executionId, 'waiting');
    }

    private function stepFailed(WorkflowStepFailed $event): void
    {
        $index = $this->runIndex($event->executionId);
        $step = $index === null ? null : $this->runs[$index]['step'];

        if ($step !== null && $step['position'] === $event->position) {
            $this->fail($step['span'], $event->errorClass, $event->error);
        }

        $this->stepEnded($event->executionId, $event->position, 'failed');
    }

    private function runFailed(WorkflowExecutionFailed $event): void
    {
        $index = $this->runIndex($event->executionId);

        if ($index !== null) {
            $this->fail($this->runs[$index]['span'], $event->errorClass, $event->error);
            $this->runs[$index]['outcome'] = 'failed';
        }
    }

    private function callAnswered(ConnectorRequestSent $event): void
    {
        $call = $this->calls[array_key_last($this->calls) ?? -1] ?? null;

        if ($call === null) {
            return;
        }

        $call['span']->setAttributes([
            'http.response.status_code' => $event->status,
            'laraflow.connector.attempts' => $event->attempts,
        ]);

        // An error status, or a 200 the connector's error mapper refused
        // (Slack's ok: false), is a failed call.
        if (! $event->successful) {
            $call['span']->setAttribute('error.type', (string) $event->status);
            $call['span']->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    private function callFailed(ConnectorRequestFailed $event): void
    {
        $call = $this->calls[array_key_last($this->calls) ?? -1] ?? null;

        if ($call !== null) {
            $call['span']->setAttribute('laraflow.connector.attempts', $event->attempts);
            $this->fail($call['span'], $event->errorClass, $event->error);
        }
    }

    /**
     * The message is the event's: already redacted by the configured
     * ErrorRedactor and absent when the application withholds messages
     * (laraflow.events.include_error_messages).
     */
    private function fail(SpanInterface $span, string $errorClass, ?string $message): void
    {
        $span->setAttribute('error.type', $errorClass);
        $span->setStatus(StatusCode::STATUS_ERROR, $message);
    }

    private function outcome(int $executionId, string $outcome): void
    {
        $index = $this->runIndex($executionId);

        if ($index !== null) {
            $this->runs[$index]['outcome'] = $outcome;
        }
    }

    private function stepEnded(int $executionId, int $position, string $outcome): void
    {
        $index = $this->runIndex($executionId);

        if ($index !== null && ($this->runs[$index]['step']['position'] ?? null) === $position) {
            $this->endStep($index, $outcome);
        }
    }

    private function endStep(int $index, string $outcome): void
    {
        $step = $this->runs[$index]['step'];

        if ($step === null) {
            return;
        }

        $this->runs[$index]['step'] = null;

        $step['span']->setAttribute('laraflow.step.outcome', $outcome);
        $step['scope']->detach();
        $step['span']->end();
    }

    private function runIndex(int $executionId): ?int
    {
        for ($i = count($this->runs) - 1; $i >= 0; $i--) {
            if ($this->runs[$i]['execution']->id === $executionId) {
                return $i;
            }
        }

        return null;
    }

    private function current(): ?WorkflowExecution
    {
        $index = array_key_last($this->runs);

        return $index === null ? null : $this->runs[$index]['execution'];
    }

    /**
     * @return array<string, int|string>
     */
    private function executionAttributes(WorkflowExecution $execution): array
    {
        return array_filter([
            'laraflow.execution.id' => $execution->id,
            'laraflow.workflow' => $execution->workflow,
            'laraflow.correlation_id' => $execution->correlationId,
            'laraflow.tenant_id' => $execution->tenantId,
        ], fn (int|string|null $value): bool => $value !== null);
    }

    /**
     * The trace the execution was started in, if it remembers one.
     *
     * @return array{context: ContextInterface, span: SpanContextInterface}|null
     */
    private function origin(WorkflowExecution $execution): ?array
    {
        if ($execution->traceContext === null) {
            return null;
        }

        $context = TraceContextPropagator::getInstance()->extract(['traceparent' => $execution->traceContext], null, Context::getRoot());
        $span = Span::fromContext($context)->getContext();

        return $span->isValid() ? ['context' => $context, 'span' => $span] : null;
    }

    private function recent(WorkflowExecution $execution): bool
    {
        $created = $execution->createdAt;

        return $created instanceof DateTimeImmutable
            && $this->clock->now()->getTimestamp() - $created->getTimestamp() < $this->linkAfterSeconds;
    }

    private function same(SpanContextInterface $a, SpanContextInterface $b): bool
    {
        return $a->getTraceId() === $b->getTraceId() && $a->getSpanId() === $b->getSpanId();
    }
}
