<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Tests;

use Illuminate\Http\Request;
use Laraflow\Prometheus\Prometheus;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Workflow\WorkflowRunner;

/**
 * The scrape endpoint: the application's to mount, denied by default
 * outside `local`, and empty of nothing — each scrape reads the gauges
 * from the database.
 */
final class EndpointTest extends TestCase
{
    public function test_production_without_a_rule_is_denied(): void
    {
        $this->app['env'] = 'production';

        $this->get('/laraflow/metrics')->assertForbidden();
    }

    public function test_local_is_let_in_and_the_scrape_is_prometheus_text(): void
    {
        $this->app['env'] = 'local';

        $response = $this->get('/laraflow/metrics');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain; version=0.0.4', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# TYPE laraflow_retries_due gauge', (string) $response->getContent());
    }

    public function test_a_configured_token_must_be_sent_as_a_bearer_token(): void
    {
        $this->app['env'] = 'production';
        config()->set('laraflow-prometheus.token', 'scrape-secret');

        $this->get('/laraflow/metrics')->assertForbidden();
        $this->withToken('wrong')->get('/laraflow/metrics')->assertForbidden();
        $this->withToken('scrape-secret')->get('/laraflow/metrics')->assertOk();
    }

    public function test_a_callback_decides_alone(): void
    {
        $this->app['env'] = 'local';
        config()->set('laraflow-prometheus.token', 'scrape-secret');
        Prometheus::auth(fn (Request $request): bool => $request->query('let') === 'in');

        $this->withToken('scrape-secret')->get('/laraflow/metrics')->assertForbidden();
        $this->get('/laraflow/metrics?let=in')->assertOk();
    }

    public function test_gauges_are_read_from_the_database_on_each_scrape(): void
    {
        $this->app['env'] = 'local';
        $runner = $this->app->make(WorkflowRunner::class);

        $runner->start(new TwoStepWorkflow);
        $runner->start(new TwoStepWorkflow);

        $workflow = addslashes(TwoStepWorkflow::class);
        $this->assertStringContainsString("laraflow_executions{workflow=\"{$workflow}\",status=\"pending\"} 2", (string) $this->get('/laraflow/metrics')->getContent());

        $runner->run(new TwoStepWorkflow);
        $runner->cancel(1);

        $this->assertStringContainsString("laraflow_executions{workflow=\"{$workflow}\",status=\"pending\"} 1", (string) $this->get('/laraflow/metrics')->getContent());
    }

    public function test_recorded_events_appear_in_the_next_scrape(): void
    {
        $this->app['env'] = 'local';
        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $workflow = addslashes(TwoStepWorkflow::class);
        $scrape = (string) $this->get('/laraflow/metrics')->getContent();

        $this->assertStringContainsString("laraflow_executions_started_total{workflow=\"{$workflow}\"} 1", $scrape);
        $this->assertStringContainsString("laraflow_executions_finished_total{workflow=\"{$workflow}\",outcome=\"completed\"} 1", $scrape);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laraflow-prometheus.enabled', true);
        $app['config']->set('laraflow-prometheus.storage', 'memory');
    }

    protected function defineRoutes($router): void
    {
        Prometheus::routes();
    }
}
