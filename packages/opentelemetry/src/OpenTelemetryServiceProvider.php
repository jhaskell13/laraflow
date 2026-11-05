<?php

declare(strict_types=1);

namespace Laraflow\OpenTelemetry;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laraflow\Observability\Tracing\NullTracer;
use Laraflow\Observability\Tracing\Tracer;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Clock\ClockInterface;

/**
 * laraflow/opentelemetry: Laraflow's spans (docs/tracing.md) through the
 * OpenTelemetry API.
 *
 * Off until `laraflow-opentelemetry.enabled`. The tracer provider is the
 * application's: one bound in the container if there is one, otherwise
 * OpenTelemetry's global provider — which is a no-op until an SDK is
 * configured, so installing this changes nothing that exports.
 */
final class OpenTelemetryServiceProvider extends ServiceProvider
{
    public const SCOPE = 'laraflow';

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/laraflow-opentelemetry.php', 'laraflow-opentelemetry');

        // Bound in register() so the core's own bindIf() — whichever
        // provider runs first — never replaces it; whether it is on is
        // asked when the tracer is first needed, after configuration has
        // settled, not while providers are still registering.
        $this->app->singleton(OpenTelemetryTracer::class, function (Container $app): OpenTelemetryTracer {
            $provider = $app->bound(TracerProviderInterface::class)
                ? $app->make(TracerProviderInterface::class)
                : Globals::tracerProvider();

            return new OpenTelemetryTracer(
                $provider->getTracer(self::SCOPE),
                $app->make(ClockInterface::class),
                (int) $app->make(Config::class)->get('laraflow-opentelemetry.link_after', 3600),
            );
        });

        $this->app->singleton(Tracer::class, fn (Container $app): Tracer => $this->enabled()
            ? $app->make(OpenTelemetryTracer::class)
            : new NullTracer);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__).'/config/laraflow-opentelemetry.php' => $this->app->configPath('laraflow-opentelemetry.php'),
            ], 'laraflow-opentelemetry-config');
        }

        if (! $this->enabled()) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);

        foreach (OpenTelemetryTracer::events() as $event) {
            $events->listen($event, fn (object $fired) => $this->app->make(OpenTelemetryTracer::class)->record($fired));
        }
    }

    private function enabled(): bool
    {
        return (bool) $this->app->make(Config::class)->get('laraflow-opentelemetry.enabled', false);
    }
}
