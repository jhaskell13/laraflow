<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Prometheus\Prometheus;
use Laraflow\Prometheus\PrometheusSink;

/**
 * docs/metrics.md, "What every bridge must do": installed and not
 * enabled, the bridge changes nothing — and an endpoint mounted anyway
 * is absent, not an empty page that looks like a healthy target.
 */
final class InstallTest extends TestCase
{
    public function test_installing_the_bridge_and_doing_nothing_else_changes_nothing(): void
    {
        $this->assertFalse(config('laraflow-prometheus.enabled'));
        $this->assertFalse(config('laraflow-prometheus.tenant_label'));
        $this->assertFalse(Event::hasListeners(WorkflowExecutionStarted::class));
        $this->assertFalse($this->app->bound(PrometheusSink::class));
    }

    public function test_a_mounted_endpoint_on_a_disabled_bridge_is_a_404(): void
    {
        $this->app['env'] = 'local';

        $this->get('/laraflow/metrics')->assertNotFound();
    }

    public function test_the_wipe_command_refuses_on_a_disabled_bridge(): void
    {
        $this->assertSame(1, Artisan::call('laraflow:prometheus-wipe'));
    }

    protected function defineRoutes($router): void
    {
        Prometheus::routes();
    }
}
