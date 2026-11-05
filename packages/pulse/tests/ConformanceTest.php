<?php

declare(strict_types=1);

namespace Laraflow\Pulse\Tests;

use Illuminate\Support\Facades\DB;
use Laraflow\Pulse\PulseSink;
use Laraflow\Testing\MetricsConformance;
use Laravel\Pulse\Facades\Pulse;

/**
 * docs/metrics.md, rule 2, read back from Pulse's own tables after a
 * real ingest — what the dashboard will query.
 */
final class ConformanceTest extends TestCase
{
    public function test_pulse_holds_exactly_the_documented_families(): void
    {
        foreach ([false, true] as $tenantLabel) {
            Pulse::purge();
            $kit = new MetricsConformance($tenantLabel);

            $kit->replay(new PulseSink($this->app->make(\Laravel\Pulse\Pulse::class)));
            Pulse::ingest();

            $kit->assertExposes($this->families());
        }
    }

    public function test_counters_sum_durations_are_milliseconds_and_gauges_replace_whole(): void
    {
        $kit = new MetricsConformance;
        $sink = new PulseSink($this->app->make(\Laravel\Pulse\Pulse::class));
        $kit->replay($sink);
        $kit->replay($sink);
        Pulse::ingest();

        $key = PulseSink::key(['workflow' => MetricsConformance::WORKFLOW, 'outcome' => 'completed']);

        $this->assertSame(2.0, (float) DB::table('pulse_aggregates')->where(['type' => 'laraflow_executions_finished_total', 'key' => $key, 'aggregate' => 'sum', 'period' => 60])->value('value'));
        $this->assertSame(1500.0, (float) DB::table('pulse_aggregates')->where(['type' => 'laraflow_execution_duration_seconds', 'key' => $key, 'aggregate' => 'max', 'period' => 60])->value('value'));

        // Republished without the workflow: its gauge is gone, not frozen.
        $sink->replaceGauge($kit->catalog()->get('laraflow_executions'), []);
        Pulse::ingest();

        $this->assertSame(0, DB::table('pulse_values')->where('type', 'laraflow_executions')->count());
        $this->assertSame(1, DB::table('pulse_values')->where('type', 'laraflow_retries_due')->count());
    }
}
