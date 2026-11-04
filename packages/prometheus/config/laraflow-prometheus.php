<?php

declare(strict_types=1);

/*
 * laraflow/prometheus. Nothing here takes effect until `enabled` is
 * true: installing the bridge and doing nothing else changes nothing
 * (docs/metrics.md, "What every bridge must do").
 */
return [

    'enabled' => (bool) env('LARAFLOW_PROMETHEUS_ENABLED', false),

    /*
     * Add the `tenant` label to every execution, step, compensation, and
     * connector series. Off by default: a tenant id is bounded by your
     * customer list, which may be ten or a hundred thousand, and every
     * tenant multiplies every series it appears on (docs/metrics.md).
     */
    'tenant_label' => (bool) env('LARAFLOW_PROMETHEUS_TENANT_LABEL', false),

    /*
     * Where counters and histograms are kept: `redis` (shared by every
     * process; the default), `apcu` (one host only), or `memory` (this
     * process only — tests). Gauges are never stored: each scrape reads
     * them from the database.
     */
    'storage' => env('LARAFLOW_PROMETHEUS_STORAGE', 'redis'),

    // The key prefix in Redis, or the APCu prefix.
    'prefix' => env('LARAFLOW_PROMETHEUS_PREFIX', 'laraflow_prometheus'),

    'redis' => [
        // A connection name from config/database.php's `redis` section.
        'connection' => env('LARAFLOW_PROMETHEUS_REDIS_CONNECTION', 'default'),
        // Seconds. Short on purpose: recording runs inside your workers.
        'timeout' => 0.1,
        'read_timeout' => 10,
        'persistent' => false,
    ],

    /*
     * The scrape endpoint, mounted by the application with
     * Laraflow\Prometheus\Prometheus::routes(). No middleware by
     * default: a scraper carries no session.
     */
    'route' => [
        'path' => env('LARAFLOW_PROMETHEUS_PATH', 'laraflow/metrics'),
        'middleware' => [],
    ],

    /*
     * A bearer token the scraper must send (Prometheus's `bearer_token`
     * option). Unset, only the `local` environment may scrape unless
     * Prometheus::auth() says otherwise.
     */
    'token' => env('LARAFLOW_PROMETHEUS_TOKEN'),

    /*
     * Histogram buckets in seconds, by family, replacing the defaults
     * docs/metrics.md lists. Run `php artisan laraflow:prometheus-wipe`
     * after changing them.
     *
     * 'laraflow_step_duration_seconds' => [0.1, 0.5, 1, 5, 30],
     */
    'buckets' => [],

];
