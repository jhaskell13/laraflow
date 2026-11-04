<?php

declare(strict_types=1);

namespace Laraflow\Observability\Metrics;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * What every metrics bridge's service provider shares, so the rules
 * docs/metrics.md sets for a bridge are kept in one place rather than
 * once per bridge:
 *
 * - **Its own configuration file**, merged under the bridge's name and
 *   published under `{name}-config` — never a key in `laraflow.php`.
 * - **Off by default.** Nothing is subscribed until `{name}.enabled` is
 *   true; installing a bridge and doing nothing else changes nothing.
 * - **The tenant label is opt-in**, read from `{name}.tenant_label`.
 * - **One mapping.** The bridge supplies a sink; the events it renders
 *   are chosen by EventMetrics, here, for every bridge alike.
 *
 * A bridge extends this, names its configuration, and builds its sink.
 * Building the sink must not perform I/O — it happens on every boot of
 * an enabled application, request or not; connect on first use.
 */
abstract class MetricsBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), $this->configName());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath($this->configName().'.php'),
            ], $this->configName().'-config');
        }

        if (! $this->enabled()) {
            return;
        }

        $catalog = new MetricCatalog((bool) $this->config('tenant_label', false));

        (new EventMetrics($this->sink($catalog), $catalog))->subscribe($this->app->make(Dispatcher::class));

        $this->bootBridge($catalog);
    }

    /**
     * The configuration file's name without `.php`, e.g. `laraflow-prometheus`;
     * also the configuration key and the publish tag's prefix.
     */
    abstract protected function configName(): string;

    /** The absolute path of the configuration file the bridge ships. */
    abstract protected function configPath(): string;

    /**
     * The sink the events are rendered into. Called once per boot, and
     * only when the bridge is enabled.
     */
    abstract protected function sink(MetricCatalog $catalog): MetricsSink;

    /**
     * Anything else an enabled bridge mounts — a command, a recorder.
     * A route an application must expose stays the application's to
     * mount, as the dashboard's is.
     */
    protected function bootBridge(MetricCatalog $catalog): void {}

    protected function enabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make(Config::class)->get($this->configName().'.'.$key, $default);
    }
}
