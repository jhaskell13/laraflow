<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Observability;

use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\File;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowScheduleTicked;
use Laraflow\Observability\Metrics\EventMetrics;
use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\SnapshotMetrics;
use Laraflow\Observability\MetricsSnapshot;
use Laraflow\Testing\MetricsConformance;
use Laraflow\Testing\RecordingMetricsSink;
use Laraflow\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The one mapping every bridge renders (docs/metrics.md): checked by
 * the same conformance kit the bridges run, and value by value where a
 * conversion could go wrong.
 */
class MetricsMappingTest extends TestCase
{
    public function test_the_core_mapping_publishes_exactly_the_documented_families(): void
    {
        foreach ([false, true] as $tenantLabel) {
            $kit = new MetricsConformance($tenantLabel);
            $sink = new RecordingMetricsSink;

            $kit->replay($sink);

            $kit->assertExposes($kit->exposedBy($sink));
        }
    }

    public function test_every_event_laraflow_fires_is_mapped_or_named_as_unmapped(): void
    {
        $unaccounted = [];

        foreach (File::files(dirname(__DIR__, 3).'/src/Events') as $file) {
            $class = 'Laraflow\\Events\\'.$file->getFilenameWithoutExtension();

            if (in_array($file->getFilenameWithoutExtension(), ['EventEmitter', 'LifecycleLogger'], true)) {
                continue;
            }

            if (! in_array($class, EventMetrics::events(), true) && ! array_key_exists($class, EventMetrics::UNMAPPED)) {
                $unaccounted[] = $class;
            }
        }

        $this->assertSame([], $unaccounted, 'A new event must become a series or say why it does not.');
        $this->assertSame([], array_intersect(EventMetrics::events(), array_keys(EventMetrics::UNMAPPED)));
    }

    public function test_milliseconds_become_seconds_and_a_missing_measure_is_not_an_observation_of_zero(): void
    {
        $sink = new RecordingMetricsSink;
        $metrics = new EventMetrics($sink, new MetricCatalog);
        $at = new DateTimeImmutable;

        $metrics->record(new WorkflowExecutionCompleted(1, 'W', $at, durationMs: 1500));
        $metrics->record(new WorkflowExecutionCompleted(2, 'W', $at, durationMs: null));
        $metrics->record(new WorkflowExecutionStarted(1, 'W', $at, queueLagSeconds: 4));
        $metrics->record(new WorkflowExecutionStarted(2, 'W', $at, queueLagSeconds: null));

        $this->assertSame(2.0, $sink->counter('laraflow_executions_finished_total', ['workflow' => 'W', 'outcome' => 'completed']));
        $this->assertSame([1.5], $sink->observed('laraflow_execution_duration_seconds', ['workflow' => 'W', 'outcome' => 'completed']));
        $this->assertSame(2.0, $sink->counter('laraflow_executions_started_total', ['workflow' => 'W']));
        $this->assertSame([4.0], $sink->observed('laraflow_execution_queue_lag_seconds', ['workflow' => 'W']), 'Running early is no wait, not a wait of zero.');
    }

    public function test_connector_calls_are_classed_by_response_and_count_their_repeats(): void
    {
        $sink = new RecordingMetricsSink;
        $metrics = new EventMetrics($sink, new MetricCatalog);

        $metrics->record(new ConnectorRequestSent('crm', 'eu', 'GET', '/a', 204, true, 50, 1));
        $metrics->record(new ConnectorRequestSent('crm', 'eu', 'GET', '/a', 404, false, 70, 1));
        $metrics->record(new ConnectorRequestSent('crm', 'eu', 'GET', '/a', 200, false, 70, 3));
        $metrics->record(new ConnectorRequestFailed('crm', 'eu', 'GET', '/a', 'X', null, 9000, 2));

        $call = fn (string $class, string $outcome): array => ['connector' => 'crm', 'connection' => 'eu', 'method' => 'GET', 'status_class' => $class, 'outcome' => $outcome];

        $this->assertSame(1.0, $sink->counter('laraflow_connector_requests_total', $call('2xx', 'success')));
        $this->assertSame(1.0, $sink->counter('laraflow_connector_requests_total', $call('4xx', 'error')));
        // A 200 the error mapper rejected (Slack's ok: false) is an error.
        $this->assertSame(1.0, $sink->counter('laraflow_connector_requests_total', $call('2xx', 'error')));
        $this->assertSame(1.0, $sink->counter('laraflow_connector_requests_total', $call('', 'failed')));
        $this->assertSame([9.0], $sink->observed('laraflow_connector_request_duration_seconds', ['connector' => 'crm', 'connection' => 'eu', 'outcome' => 'failed']));
        $this->assertSame(3.0, $sink->counter('laraflow_connector_retries_total', ['connector' => 'crm', 'connection' => 'eu']), 'Three attempts are two repeats, two attempts one.');
    }

    public function test_the_tenant_label_is_off_by_default_empty_when_unknown_and_only_where_attributable(): void
    {
        $off = new RecordingMetricsSink;
        (new EventMetrics($off, new MetricCatalog))->record(new WorkflowExecutionStarted(1, 'W', new DateTimeImmutable, tenantId: 'acme'));
        $this->assertSame([['workflow' => 'W']], $off->labelSets('laraflow_executions_started_total'));

        $on = new RecordingMetricsSink;
        $metrics = new EventMetrics($on, new MetricCatalog(tenantLabel: true));
        $metrics->record(new WorkflowExecutionStarted(1, 'W', new DateTimeImmutable, tenantId: 'acme'));
        $metrics->record(new WorkflowExecutionStarted(2, 'W', new DateTimeImmutable, tenantId: null));
        $metrics->record(new WorkflowScheduleTicked(3, 'W', null, 'acme', new DateTimeImmutable, 0, false));

        $this->assertSame([
            ['workflow' => 'W', 'tenant' => 'acme'],
            ['workflow' => 'W', 'tenant' => ''],
        ], $on->labelSets('laraflow_executions_started_total'));
        $this->assertSame([['workflow' => 'W', 'outcome' => 'started']], $on->labelSets('laraflow_schedule_ticks_total'), 'Schedule series never take the tenant label.');
    }

    public function test_gauges_are_replaced_whole_with_drained_statuses_at_zero_and_no_age_when_nothing_is_unfinished(): void
    {
        $sink = new RecordingMetricsSink;
        $gauges = new SnapshotMetrics($sink, new MetricCatalog);

        $gauges->publish($this->snapshot(['A' => ['pending' => 2], 'B' => ['failed' => 1]], age: 30));

        $this->assertCount(2 * count(MetricsSnapshot::STATUSES), $sink->gauges['laraflow_executions']);
        $this->assertContains(['labels' => ['workflow' => 'A', 'status' => 'pending'], 'value' => 2.0], $sink->gauges['laraflow_executions']);
        $this->assertContains(['labels' => ['workflow' => 'A', 'status' => 'failed'], 'value' => 0.0], $sink->gauges['laraflow_executions']);
        $this->assertSame([['labels' => [], 'value' => 30.0]], $sink->gauges['laraflow_oldest_unfinished_execution_age_seconds']);

        // B drained entirely, and nothing is unfinished any more.
        $gauges->publish($this->snapshot(['A' => ['failed' => 1]], age: null));

        $this->assertSame([], array_filter($sink->gauges['laraflow_executions'], fn (array $s): bool => $s['labels']['workflow'] === 'B'));
        $this->assertSame([], $sink->gauges['laraflow_oldest_unfinished_execution_age_seconds'], 'An age of zero would claim something was created this instant.');
    }

    public function test_subscribing_records_what_the_dispatcher_fires(): void
    {
        $sink = new RecordingMetricsSink;
        (new EventMetrics($sink, new MetricCatalog))->subscribe($this->app->make(Dispatcher::class));

        event(new WorkflowExecutionStarted(1, 'W', new DateTimeImmutable));

        $this->assertSame(1.0, $sink->counter('laraflow_executions_started_total', ['workflow' => 'W']));
    }

    public function test_the_kit_fails_a_bridge_that_drops_retypes_relabels_or_invents_a_family(): void
    {
        $kit = new MetricsConformance;
        $sink = new RecordingMetricsSink;
        $kit->replay($sink);
        $good = $kit->exposedBy($sink);

        $dropped = $good;
        unset($dropped['laraflow_retries_due']);

        $retyped = $good;
        $retyped['laraflow_executions_started_total'] = ['type' => 'gauge', 'labels' => $good['laraflow_executions_started_total']['labels']];

        $relabelled = $good;
        $relabelled['laraflow_executions_started_total'] = [
            'type' => 'counter',
            'labels' => [...$good['laraflow_executions_started_total']['labels'], 'execution_id'],
        ];

        $invented = $good + ['laraflow_mystery_total' => ['type' => 'counter', 'labels' => []]];

        foreach (['dropped' => $dropped, 'retyped' => $retyped, 'relabelled' => $relabelled, 'invented' => $invented] as $case => $exposed) {
            try {
                $kit->assertExposes($exposed);
                $this->fail("The kit passed a bridge whose family was {$case}.");
            } catch (AssertionFailedError $failed) {
                $this->assertStringContainsString('does not publish what docs/metrics.md defines', $failed->getMessage());
            }
        }

        // A declared omission passes; an exposed family declared omitted does not.
        $kit->assertExposes($dropped, omitted: ['laraflow_retries_due']);
        $this->expectException(AssertionFailedError::class);
        $kit->assertExposes($good, omitted: ['laraflow_retries_due']);
    }

    /**
     * @param  array<string, array<string, int>>  $executions
     */
    private function snapshot(array $executions, ?int $age): MetricsSnapshot
    {
        return new MetricsSnapshot($executions, $age, 0, 0, 0, 0, 0, new DateTimeImmutable);
    }
}
