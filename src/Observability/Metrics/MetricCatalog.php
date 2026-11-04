<?php

declare(strict_types=1);

namespace Laraflow\Observability\Metrics;

use InvalidArgumentException;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * Every series docs/metrics.md defines, as data: the one list the event
 * mapping, the snapshot mapping, every sink's registration, and the
 * conformance kit read. A contract test holds the document's tables and
 * this list to each other, so neither can gain or lose a series, a
 * label, or a type without the other.
 *
 * The tenant label is opt-in (docs/metrics.md, "The tenant label is
 * opt-in"). A catalog built with it adds `tenant`, last, to every
 * execution, step, compensation, and connector family — the series an
 * event can attribute to a tenant — and to no other.
 */
final class MetricCatalog
{
    /** Step and connector call durations: milliseconds to minutes. */
    public const FAST_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 120.0, 300.0];

    /** Whole executions, waits included: seconds to a week. */
    public const EXECUTION_BUCKETS = [0.1, 0.5, 1.0, 5.0, 10.0, 30.0, 60.0, 300.0, 900.0, 3600.0, 21600.0, 86400.0, 604800.0];

    /** Time an execution waited for a worker. */
    public const LAG_BUCKETS = [0.1, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 300.0, 900.0, 3600.0];

    /** @var array<string, MetricDefinition> */
    private array $definitions = [];

    public function __construct(public readonly bool $tenantLabel = false)
    {
        $counter = MetricType::Counter;
        $histogram = MetricType::Histogram;
        $gauge = MetricType::Gauge;

        // Executions.
        $this->add('laraflow_executions_started_total', $counter, 'Executions a worker or an inline run started.', ['workflow'], tenant: true);
        $this->add('laraflow_executions_finished_total', $counter, 'Executions that reached completed, failed, or cancelled.', ['workflow', 'outcome'], tenant: true);
        $this->add('laraflow_execution_duration_seconds', $histogram, 'Seconds from an execution\'s start to its finish.', ['workflow', 'outcome'], self::EXECUTION_BUCKETS, tenant: true);
        $this->add('laraflow_execution_queue_lag_seconds', $histogram, 'Seconds an execution waited for a worker past its due time.', ['workflow'], self::LAG_BUCKETS, tenant: true);
        $this->add('laraflow_executions_dispatched_total', $counter, 'Executions handed to the queue.', ['workflow', 'queue'], tenant: true);
        $this->add('laraflow_execution_retries_scheduled_total', $counter, 'Executions scheduled for another delivery, by why.', ['workflow', 'reason'], tenant: true);
        $this->add('laraflow_executions_deferred_total', $counter, 'Claims a tenant ceiling turned away.', ['workflow', 'ceiling'], tenant: true);
        $this->add('laraflow_execution_failures_total', $counter, 'Executions that failed, by exception class.', ['workflow', 'error_class'], tenant: true);
        $this->add('laraflow_signals_received_total', $counter, 'Signals recorded for an execution.', ['workflow'], tenant: true);
        $this->add('laraflow_plan_migrations_total', $counter, 'Executions moved to a newer plan version.', ['workflow'], tenant: true);

        // Steps.
        $this->add('laraflow_steps_finished_total', $counter, 'Step attempts that completed, failed, or were skipped.', ['workflow', 'step', 'outcome'], tenant: true);
        $this->add('laraflow_step_duration_seconds', $histogram, 'Seconds one step attempt ran.', ['workflow', 'step', 'outcome'], self::FAST_BUCKETS, tenant: true);
        $this->add('laraflow_step_failures_total', $counter, 'Step attempts that failed, by exception class.', ['workflow', 'step', 'error_class'], tenant: true);
        $this->add('laraflow_step_retries_scheduled_total', $counter, 'Step retries scheduled.', ['workflow', 'step'], tenant: true);
        $this->add('laraflow_step_waits_total', $counter, 'Steps that parked on a signal or a delay.', ['workflow', 'step', 'kind'], tenant: true);
        $this->add('laraflow_fan_out_members_total', $counter, 'Child executions a fan-out step started.', ['workflow', 'step'], tenant: true);

        // Compensation.
        $this->add('laraflow_compensations_started_total', $counter, 'Compensation passes begun.', ['workflow'], tenant: true);
        $this->add('laraflow_compensations_finished_total', $counter, 'Compensation passes ended, complete or with steps left to undo.', ['workflow', 'outcome'], tenant: true);

        // Schedules and triggers.
        $this->add('laraflow_schedule_ticks_total', $counter, 'Schedule ticks started, caught up, or skipped for overlap.', ['workflow', 'outcome']);
        $this->add('laraflow_schedule_missed_ticks_total', $counter, 'Ticks a schedule missed and did not run.', ['workflow']);
        $this->add('laraflow_trigger_requests_total', $counter, 'Trigger requests accepted or refused.', ['trigger', 'outcome', 'reason']);

        // Connectors.
        $this->add('laraflow_connector_requests_total', $counter, 'Connector calls, by response.', ['connector', 'connection', 'method', 'status_class', 'outcome'], tenant: true);
        $this->add('laraflow_connector_request_duration_seconds', $histogram, 'Seconds a whole connector call took, retries and throttle waits included.', ['connector', 'connection', 'outcome'], self::FAST_BUCKETS, tenant: true);
        $this->add('laraflow_connector_retries_total', $counter, 'Wire attempts a transport retry policy repeated.', ['connector', 'connection'], tenant: true);

        // Gauges, from the snapshot. Never the tenant label: the
        // snapshot is unscoped (docs/metrics.md).
        $this->add('laraflow_executions', $gauge, 'Unfinished and failed executions, by workflow and status.', ['workflow', 'status']);
        $this->add('laraflow_oldest_unfinished_execution_age_seconds', $gauge, 'Seconds since the oldest unfinished execution was created.');
        $this->add('laraflow_retries_due', $gauge, 'Retrying executions whose backoff has elapsed.');
        $this->add('laraflow_waits_due', $gauge, 'Waiting executions whose resume time or deadline has passed.');
        $this->add('laraflow_steps_overdue', $gauge, 'Executions with a running step past its deadline.');
        $this->add('laraflow_executions_deferred', $gauge, 'Executions a tenant ceiling is holding.');
        $this->add('laraflow_schedules_stalled', $gauge, 'Schedules with due ticks standing unclaimed.');
    }

    /**
     * @return list<MetricDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @throws InvalidArgumentException for a name docs/metrics.md does not define.
     */
    public function get(string $name): MetricDefinition
    {
        return $this->definitions[$name]
            ?? throw new InvalidArgumentException("[{$name}] is not a series docs/metrics.md defines.");
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float>  $buckets
     */
    private function add(string $name, MetricType $type, string $help, array $labels = [], array $buckets = [], bool $tenant = false): void
    {
        if ($tenant && $this->tenantLabel) {
            $labels[] = 'tenant';
        }

        $this->definitions[$name] = new MetricDefinition($name, $type, $help, $labels, $buckets);
    }
}
