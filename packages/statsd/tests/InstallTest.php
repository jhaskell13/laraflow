<?php

declare(strict_types=1);

namespace Laraflow\StatsD\Tests;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\StatsD\StatsDSink;
use Laraflow\StatsD\Transport;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Workflow\WorkflowRunner;

/**
 * Off until enabled; enabled, events become datagrams as they fire and
 * the scheduled command pushes the gauges from a real snapshot.
 */
final class InstallTest extends TestCase
{
    public function test_installing_the_bridge_and_doing_nothing_else_changes_nothing(): void
    {
        $this->assertFalse(config('laraflow-statsd.enabled'));
        $this->assertFalse(Event::hasListeners(WorkflowExecutionStarted::class));
        $this->assertFalse($this->app->bound(StatsDSink::class));
        $this->assertSame(1, Artisan::call('laraflow:statsd-gauges'));
    }

    public function test_enabled_it_sends_as_events_fire_and_pushes_gauges_on_command(): void
    {
        $transport = new RecordingTransport;
        $this->app->instance(Transport::class, $transport);
        config()->set('laraflow-statsd.enabled', true);
        $this->app->register(\Laraflow\StatsD\StatsDServiceProvider::class, force: true);

        event(new WorkflowExecutionStarted(1, 'W', new DateTimeImmutable));
        $this->assertContains('laraflow_executions_started_total:1|c|#workflow:W', $transport->sent);

        $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);
        $this->assertSame(0, Artisan::call('laraflow:statsd-gauges'));

        $this->assertContains('laraflow_executions:1|g|#workflow:'.TwoStepWorkflow::class.',status:pending', $transport->sent);
        $this->assertContains('laraflow_retries_due:0|g', $transport->sent);
    }
}
