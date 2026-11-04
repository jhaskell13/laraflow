<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Tests;

use Laraflow\Prometheus\PrometheusSink;
use Laraflow\Testing\MetricsConformance;
use Prometheus\Storage\InMemory;

/**
 * docs/metrics.md, rule 2: exactly the documented families, read back
 * from the exposition text a scraper receives — not from the sink.
 */
final class ConformanceTest extends TestCase
{
    public function test_the_scrape_exposes_exactly_the_documented_families(): void
    {
        foreach ([false, true] as $tenantLabel) {
            $kit = new MetricsConformance($tenantLabel);
            $sink = new PrometheusSink(fn () => new InMemory);

            $kit->replay($sink);

            $kit->assertExposes($this->families($sink->render()));
        }
    }

    public function test_values_and_seconds_survive_to_the_text(): void
    {
        $kit = new MetricsConformance;
        $sink = new PrometheusSink(fn () => new InMemory);
        $kit->replay($sink);

        $scrape = $sink->render();
        $w = addslashes(MetricsConformance::WORKFLOW);

        $this->assertStringContainsString("laraflow_executions_finished_total{workflow=\"{$w}\",outcome=\"completed\"} 1", $scrape);
        $this->assertStringContainsString("laraflow_execution_duration_seconds_sum{workflow=\"{$w}\",outcome=\"completed\"} 1.5", $scrape);
        $this->assertStringContainsString('laraflow_connector_retries_total{connector="crm",connection="default"} 3', $scrape);
        $this->assertStringContainsString('laraflow_retries_due 1', $scrape);
        $this->assertStringContainsString("laraflow_executions{workflow=\"{$w}\",status=\"pending\"} 2", $scrape);
        $this->assertStringNotContainsString('php_info', $scrape, 'Nothing but Laraflow\'s families under this endpoint.');
    }

    public function test_configured_buckets_replace_the_defaults(): void
    {
        $sink = new PrometheusSink(fn () => new InMemory, buckets: ['laraflow_step_duration_seconds' => [0.5, 2.0]]);
        (new MetricsConformance)->replay($sink);

        $scrape = $sink->render();

        $this->assertStringContainsString('laraflow_step_duration_seconds_bucket{workflow=', $scrape);
        $this->assertMatchesRegularExpression('/laraflow_step_duration_seconds_bucket\{[^}]*le="2"\}/', $scrape);
        $this->assertDoesNotMatchRegularExpression('/laraflow_step_duration_seconds_bucket\{[^}]*le="0.005"\}/', $scrape);
    }
}
