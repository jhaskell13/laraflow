<?php

declare(strict_types=1);

namespace Laraflow\Observability\Metrics;

use Laraflow\Observability\MetricsSnapshot;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * Turns a MetricsSnapshot into the gauges of docs/metrics.md. Every
 * family is replaced whole on each publish, so a workflow with nothing
 * left unfinished stops being reported rather than staying frozen at
 * its last count.
 */
final class SnapshotMetrics
{
    public function __construct(
        private readonly MetricsSink $sink,
        private readonly MetricCatalog $catalog,
    ) {}

    public function publish(MetricsSnapshot $snapshot): void
    {
        $series = [];

        // Every counted status for every workflow present, zeros
        // included: a status that drained to nothing reads 0 while its
        // workflow still has other work, not its last non-zero value.
        foreach ($snapshot->workflows() as $workflow) {
            foreach (MetricsSnapshot::STATUSES as $status) {
                $series[] = [
                    'labels' => ['workflow' => $workflow, 'status' => $status->value],
                    'value' => (float) $snapshot->count($status, $workflow),
                ];
            }
        }

        $this->sink->replaceGauge($this->catalog->get('laraflow_executions'), $series);

        // Absent, not zero, when nothing is unfinished: an age of zero
        // would claim an execution was created this instant.
        $this->sink->replaceGauge(
            $this->catalog->get('laraflow_oldest_unfinished_execution_age_seconds'),
            $snapshot->oldestUnfinishedAgeSeconds === null ? [] : [['labels' => [], 'value' => (float) $snapshot->oldestUnfinishedAgeSeconds]],
        );

        foreach ([
            'laraflow_retries_due' => $snapshot->retriesDue,
            'laraflow_waits_due' => $snapshot->waitsDue,
            'laraflow_steps_overdue' => $snapshot->overdueSteps,
            'laraflow_executions_deferred' => $snapshot->deferred,
            'laraflow_schedules_stalled' => $snapshot->stalledSchedules,
        ] as $name => $value) {
            $this->sink->replaceGauge($this->catalog->get($name), [['labels' => [], 'value' => (float) $value]]);
        }
    }
}
