<?php

declare(strict_types=1);

namespace Laraflow\Pulse;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsBridgeServiceProvider;
use Laraflow\Observability\Metrics\MetricsSink;
use Laraflow\Observability\Snapshots;
use Laraflow\Pulse\Livewire\Workflows;
use Laravel\Pulse\Events\IsolatedBeat;
use Laravel\Pulse\Pulse;
use Livewire\LivewireManager;

/**
 * laraflow/pulse: Laraflow's metrics recorded into Laravel Pulse, the
 * snapshot's gauges recorded from `pulse:check`'s heartbeat, and a card
 * for the Pulse dashboard.
 *
 * The rules every bridge keeps — its own configuration, off until
 * enabled, the tenant label opt-in, the one event mapping — are
 * inherited from MetricsBridgeServiceProvider.
 */
final class PulseServiceProvider extends MetricsBridgeServiceProvider
{
    private ?PulseSink $sink = null;

    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'laraflow-pulse');

        parent::boot();
    }

    protected function configName(): string
    {
        return 'laraflow-pulse';
    }

    protected function configPath(): string
    {
        return dirname(__DIR__).'/config/laraflow-pulse.php';
    }

    protected function sink(MetricCatalog $catalog): MetricsSink
    {
        return $this->sink = new PulseSink($this->app->make(Pulse::class));
    }

    protected function bootBridge(MetricCatalog $catalog): void
    {
        $this->app->instance(PulseSink::class, $this->sink);
        $this->app->instance(MetricCatalog::class, $catalog);

        $recorder = fn (): GaugeRecorder => new GaugeRecorder(
            $this->app->make(Snapshots::class),
            $this->app->make(PulseSink::class),
            $catalog,
            (int) $this->app->make(Config::class)->get('laraflow-pulse.gauge_interval', 60),
        );

        $this->app->make(Dispatcher::class)->listen(IsolatedBeat::class, fn (IsolatedBeat $event) => $recorder()->record($event));

        $this->callAfterResolving('livewire', function (LivewireManager $livewire): void {
            $livewire->component('laraflow.workflows', Workflows::class);
        });
    }
}
