<?php

declare(strict_types=1);

namespace Laraflow\Observability\Metrics;

use Illuminate\Contracts\Events\Dispatcher;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Events\TriggerAccepted;
use Laraflow\Events\TriggerRefused;
use Laraflow\Events\WorkflowCompensated;
use Laraflow\Events\WorkflowCompensationStarted;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionDeferred;
use Laraflow\Events\WorkflowExecutionDispatched;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Events\WorkflowExecutionSignalled;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowFanOutStarted;
use Laraflow\Events\WorkflowPlanMigrated;
use Laraflow\Events\WorkflowScheduleSkipped;
use Laraflow\Events\WorkflowScheduleTicked;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Events\WorkflowStepSkipped;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Events\WorkflowStepWaiting;
use LogicException;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * Turns lifecycle events into the counters and histograms of
 * docs/metrics.md, for whichever sink a bridge provides. The mapping
 * lives here, once, rather than in each bridge: a Prometheus dashboard
 * and a StatsD one built on the same application then read the same
 * names, and a new event is mapped for every backend at once.
 *
 * Every event Laraflow fires is either mapped below or listed in
 * UNMAPPED with the reason, and a contract test holds the package to
 * that — an event added later cannot be silently forgotten.
 */
final class EventMetrics
{
    /**
     * Events that deliberately become no series.
     *
     * @var array<class-string, string>
     */
    public const UNMAPPED = [
        WorkflowStepStarted::class => 'A start is half of an attempt; steps_finished_total and step_duration_seconds count and measure the whole of it, and the overdue gauge reports a start that never finished.',
    ];

    public function __construct(
        private readonly MetricsSink $sink,
        private readonly MetricCatalog $catalog,
    ) {}

    /**
     * Record every mapped event dispatched from now on.
     */
    public function subscribe(Dispatcher $events): void
    {
        foreach (self::events() as $event) {
            $events->listen($event, $this->record(...));
        }
    }

    /**
     * The events this class turns into series.
     *
     * @return list<class-string>
     */
    public static function events(): array
    {
        return [
            WorkflowExecutionStarted::class,
            WorkflowExecutionCompleted::class,
            WorkflowExecutionFailed::class,
            WorkflowExecutionCancelled::class,
            WorkflowExecutionDispatched::class,
            WorkflowExecutionRetryScheduled::class,
            WorkflowExecutionDeferred::class,
            WorkflowExecutionSignalled::class,
            WorkflowPlanMigrated::class,
            WorkflowStepCompleted::class,
            WorkflowStepFailed::class,
            WorkflowStepSkipped::class,
            WorkflowStepRetryScheduled::class,
            WorkflowStepWaiting::class,
            WorkflowFanOutStarted::class,
            WorkflowCompensationStarted::class,
            WorkflowCompensated::class,
            WorkflowScheduleTicked::class,
            WorkflowScheduleSkipped::class,
            TriggerAccepted::class,
            TriggerRefused::class,
            ConnectorRequestSent::class,
            ConnectorRequestFailed::class,
        ];
    }

    public function record(object $event): void
    {
        match (true) {
            $event instanceof WorkflowExecutionStarted => $this->executionStarted($event),
            $event instanceof WorkflowExecutionCompleted => $this->executionFinished($event->workflow, 'completed', $event->durationMs, $event->tenantId),
            $event instanceof WorkflowExecutionCancelled => $this->executionFinished($event->workflow, 'cancelled', $event->durationMs, $event->tenantId),
            $event instanceof WorkflowExecutionFailed => $this->executionFailed($event),
            $event instanceof WorkflowExecutionDispatched => $this->count('laraflow_executions_dispatched_total', ['workflow' => $event->workflow, 'queue' => $event->queue ?? ''], $event->tenantId),
            $event instanceof WorkflowExecutionRetryScheduled => $this->count('laraflow_execution_retries_scheduled_total', ['workflow' => $event->workflow, 'reason' => $event->reason->value], $event->tenantId),
            $event instanceof WorkflowExecutionDeferred => $this->count('laraflow_executions_deferred_total', ['workflow' => $event->workflow, 'ceiling' => $event->ceiling->value], $event->tenantId),
            $event instanceof WorkflowExecutionSignalled => $this->count('laraflow_signals_received_total', ['workflow' => $event->workflow], $event->tenantId),
            $event instanceof WorkflowPlanMigrated => $this->count('laraflow_plan_migrations_total', ['workflow' => $event->workflow], $event->tenantId),
            $event instanceof WorkflowStepCompleted => $this->stepFinished($event->workflow, $event->step, 'completed', $event->durationMs, $event->tenantId),
            $event instanceof WorkflowStepFailed => $this->stepFailed($event),
            $event instanceof WorkflowStepSkipped => $this->stepFinished($event->workflow, $event->step, 'skipped', null, $event->tenantId),
            $event instanceof WorkflowStepRetryScheduled => $this->count('laraflow_step_retries_scheduled_total', ['workflow' => $event->workflow, 'step' => $event->step], $event->tenantId),
            $event instanceof WorkflowStepWaiting => $this->count('laraflow_step_waits_total', ['workflow' => $event->workflow, 'step' => $event->step, 'kind' => $event->signal !== null ? 'signal' : 'delay'], $event->tenantId),
            $event instanceof WorkflowFanOutStarted => $this->count('laraflow_fan_out_members_total', ['workflow' => $event->workflow, 'step' => $event->step], $event->tenantId, $event->members),
            $event instanceof WorkflowCompensationStarted => $this->count('laraflow_compensations_started_total', ['workflow' => $event->workflow], $event->tenantId),
            $event instanceof WorkflowCompensated => $this->count('laraflow_compensations_finished_total', ['workflow' => $event->workflow, 'outcome' => $event->complete ? 'complete' : 'incomplete'], $event->tenantId),
            $event instanceof WorkflowScheduleTicked => $this->scheduleTicked($event),
            $event instanceof WorkflowScheduleSkipped => $this->count('laraflow_schedule_ticks_total', ['workflow' => $event->workflow, 'outcome' => 'skipped_overlap']),
            $event instanceof TriggerAccepted => $this->count('laraflow_trigger_requests_total', ['trigger' => $event->trigger, 'outcome' => 'accepted', 'reason' => '']),
            $event instanceof TriggerRefused => $this->count('laraflow_trigger_requests_total', ['trigger' => $event->trigger, 'outcome' => 'refused', 'reason' => $event->reason->value]),
            $event instanceof ConnectorRequestSent => $this->connectorCall($event->connector, $event->connection, $event->method, (string) intdiv($event->status, 100).'xx', $event->successful ? 'success' : 'error', $event->durationMs, $event->attempts, $event->tenantId),
            $event instanceof ConnectorRequestFailed => $this->connectorCall($event->connector, $event->connection, $event->method, '', 'failed', $event->durationMs, $event->attempts, $event->tenantId),
            default => null,
        };
    }

    private function executionStarted(WorkflowExecutionStarted $event): void
    {
        $this->count('laraflow_executions_started_total', ['workflow' => $event->workflow], $event->tenantId);

        // Null, not zero, when the execution never waited on a queue:
        // running early is no wait at all (docs/queue-and-recovery.md),
        // and an observation of zero would say otherwise.
        if ($event->queueLagSeconds !== null) {
            $this->measure('laraflow_execution_queue_lag_seconds', ['workflow' => $event->workflow], $event->tenantId, (float) $event->queueLagSeconds);
        }
    }

    private function executionFailed(WorkflowExecutionFailed $event): void
    {
        $this->executionFinished($event->workflow, 'failed', $event->durationMs, $event->tenantId);
        $this->count('laraflow_execution_failures_total', ['workflow' => $event->workflow, 'error_class' => $event->errorClass], $event->tenantId);
    }

    private function executionFinished(string $workflow, string $outcome, ?int $durationMs, ?string $tenantId): void
    {
        $labels = ['workflow' => $workflow, 'outcome' => $outcome];

        $this->count('laraflow_executions_finished_total', $labels, $tenantId);

        if ($durationMs !== null) {
            $this->measure('laraflow_execution_duration_seconds', $labels, $tenantId, $durationMs / 1000);
        }
    }

    private function stepFailed(WorkflowStepFailed $event): void
    {
        $this->stepFinished($event->workflow, $event->step, 'failed', $event->durationMs, $event->tenantId);
        $this->count('laraflow_step_failures_total', ['workflow' => $event->workflow, 'step' => $event->step, 'error_class' => $event->errorClass], $event->tenantId);
    }

    private function stepFinished(string $workflow, string $step, string $outcome, ?int $durationMs, ?string $tenantId): void
    {
        $labels = ['workflow' => $workflow, 'step' => $step, 'outcome' => $outcome];

        $this->count('laraflow_steps_finished_total', $labels, $tenantId);

        // A skipped step did not run, so it has no duration to report.
        if ($durationMs !== null) {
            $this->measure('laraflow_step_duration_seconds', $labels, $tenantId, $durationMs / 1000);
        }
    }

    private function scheduleTicked(WorkflowScheduleTicked $event): void
    {
        $this->count('laraflow_schedule_ticks_total', ['workflow' => $event->workflow, 'outcome' => $event->caughtUp ? 'caught_up' : 'started']);

        if ($event->missed > 0) {
            $this->count('laraflow_schedule_missed_ticks_total', ['workflow' => $event->workflow], by: $event->missed);
        }
    }

    private function connectorCall(string $connector, string $connection, string $method, string $statusClass, string $outcome, int $durationMs, int $attempts, ?string $tenantId): void
    {
        $this->count('laraflow_connector_requests_total', [
            'connector' => $connector,
            'connection' => $connection,
            'method' => $method,
            'status_class' => $statusClass,
            'outcome' => $outcome,
        ], $tenantId);

        $this->measure('laraflow_connector_request_duration_seconds', [
            'connector' => $connector,
            'connection' => $connection,
            'outcome' => $outcome,
        ], $tenantId, $durationMs / 1000);

        if ($attempts > 1) {
            $this->count('laraflow_connector_retries_total', ['connector' => $connector, 'connection' => $connection], $tenantId, $attempts - 1);
        }
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function count(string $name, array $labels, ?string $tenantId = null, int $by = 1): void
    {
        $metric = $this->catalog->get($name);

        $this->sink->increment($metric, $this->labels($metric, $labels, $tenantId), (float) $by);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function measure(string $name, array $labels, ?string $tenantId, float $seconds): void
    {
        $metric = $this->catalog->get($name);

        $this->sink->observe($metric, $this->labels($metric, $labels, $tenantId), $seconds);
    }

    /**
     * The labels in the definition's order, with the tenant added when
     * the catalog carries it. A mapping that disagrees with the catalog
     * is a defect in this class, raised here rather than handed to a
     * backend that would quietly register a second family.
     *
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function labels(MetricDefinition $metric, array $labels, ?string $tenantId): array
    {
        if (in_array('tenant', $metric->labels, true)) {
            $labels['tenant'] = $tenantId ?? '';
        }

        $ordered = [];

        foreach ($metric->labels as $name) {
            if (! array_key_exists($name, $labels)) {
                throw new LogicException("[{$metric->name}] was recorded without its [{$name}] label.");
            }

            $ordered[$name] = $labels[$name];
        }

        if (count($ordered) !== count($labels)) {
            throw new LogicException("[{$metric->name}] was recorded with labels docs/metrics.md does not define.");
        }

        return $ordered;
    }
}
