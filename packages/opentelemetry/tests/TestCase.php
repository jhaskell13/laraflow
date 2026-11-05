<?php

declare(strict_types=1);

namespace Laraflow\OpenTelemetry\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\OpenTelemetry\OpenTelemetryServiceProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Testbench declares this `Application|null`: null only before the
     * application is created, never while a test method runs. Narrowed
     * as the core suite narrows it (tests/TestCase.php).
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    protected InMemoryExporter $exporter;

    protected TracerProvider $provider;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaraflowServiceProvider::class, OpenTelemetryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        // The application's SDK, as a real one would be configured: a
        // provider in the container, exporting to memory.
        $this->exporter = new InMemoryExporter;
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $app->instance(TracerProviderInterface::class, $this->provider);
    }

    /**
     * Every finished span, by name.
     *
     * @return array<string, ImmutableSpan>
     */
    protected function spans(): array
    {
        $spans = [];

        foreach ($this->exporter->getSpans() as $span) {
            if ($span instanceof ImmutableSpan) {
                $spans[$span->getName()] = $span;
            }
        }

        return $spans;
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(ImmutableSpan $span): array
    {
        return $span->getAttributes()->toArray();
    }
}
