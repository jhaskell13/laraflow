<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Observability;

use DateTimeImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Tests\Support\Metrics\RecordingBridgeServiceProvider;
use Laraflow\Tests\TestCase;

/**
 * The rules every bridge inherits (docs/metrics.md, "What every bridge
 * must do"): its own configuration, off until enabled, the tenant label
 * opt-in, and the one mapping.
 */
class MetricsBridgeServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingBridgeServiceProvider::$sink = null;
        RecordingBridgeServiceProvider::$catalog = null;
    }

    public function test_installing_a_bridge_and_doing_nothing_else_changes_nothing(): void
    {
        $this->assertFalse(Event::hasListeners(WorkflowExecutionStarted::class));

        $this->app->register(RecordingBridgeServiceProvider::class);

        $this->assertSame(false, config('laraflow-recording.enabled'), 'Configuration is merged under the bridge\'s own name.');
        $this->assertNull(config('laraflow.recording'), 'Nothing is added to the core configuration.');
        $this->assertNull(RecordingBridgeServiceProvider::$sink, 'No sink is built while the bridge is off.');
        $this->assertFalse(Event::hasListeners(WorkflowExecutionStarted::class));
    }

    public function test_an_enabled_bridge_renders_every_event_through_the_one_mapping(): void
    {
        config()->set('laraflow-recording.enabled', true);

        $this->app->register(RecordingBridgeServiceProvider::class);
        event(new WorkflowExecutionStarted(1, 'W', new DateTimeImmutable, tenantId: 'acme'));

        $this->assertSame(1.0, RecordingBridgeServiceProvider::$sink?->counter('laraflow_executions_started_total', ['workflow' => 'W']));
        $this->assertFalse(RecordingBridgeServiceProvider::$catalog?->tenantLabel);
    }

    public function test_the_tenant_label_follows_the_bridges_own_switch(): void
    {
        config()->set('laraflow-recording.enabled', true);
        config()->set('laraflow-recording.tenant_label', true);

        $this->app->register(RecordingBridgeServiceProvider::class);
        event(new WorkflowExecutionStarted(1, 'W', new DateTimeImmutable, tenantId: 'acme'));

        $this->assertSame(1.0, RecordingBridgeServiceProvider::$sink?->counter('laraflow_executions_started_total', ['workflow' => 'W', 'tenant' => 'acme']));
    }

    public function test_the_configuration_publishes_under_the_bridges_own_tag(): void
    {
        $this->app->register(RecordingBridgeServiceProvider::class);

        $paths = ServiceProvider::pathsToPublish(RecordingBridgeServiceProvider::class, 'laraflow-recording-config');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('config/laraflow-recording.php', (string) array_values($paths)[0]);
    }
}
