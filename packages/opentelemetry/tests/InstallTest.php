<?php

declare(strict_types=1);

namespace Laraflow\OpenTelemetry\Tests;

use Laraflow\Observability\Tracing\NullTracer;
use Laraflow\Observability\Tracing\Tracer;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Workflow\WorkflowRunner;

/**
 * Installed and not enabled, the bridge changes nothing: the core keeps
 * its NullTracer, and an SDK the application configured records nothing
 * from Laraflow.
 */
final class InstallTest extends TestCase
{
    public function test_installing_the_bridge_and_doing_nothing_else_changes_nothing(): void
    {
        $this->assertFalse(config('laraflow-opentelemetry.enabled'));
        $this->assertInstanceOf(NullTracer::class, $this->app->make(Tracer::class));

        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $this->assertSame([], $this->exporter->getSpans());
    }
}
