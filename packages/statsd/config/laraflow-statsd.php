<?php

declare(strict_types=1);

/*
 * laraflow/statsd. Nothing here takes effect until `enabled` is true:
 * installing the bridge and doing nothing else changes nothing
 * (docs/metrics.md, "What every bridge must do").
 */
return [

    'enabled' => (bool) env('LARAFLOW_STATSD_ENABLED', false),

    /*
     * Add the `tenant` tag to every execution, step, compensation, and
     * connector series. Off by default: a tenant id is bounded by your
     * customer list, and every tenant multiplies every series it
     * appears on (docs/metrics.md).
     */
    'tenant_label' => (bool) env('LARAFLOW_STATSD_TENANT_LABEL', false),

    // The collector: a Datadog agent, Telegraf, statsd, or StatsD Exporter.
    'host' => env('LARAFLOW_STATSD_HOST', '127.0.0.1'),
    'port' => (int) env('LARAFLOW_STATSD_PORT', 8125),

    /*
     * How labels travel: `dogstatsd` (`|#key:value`, the default) or
     * `graphite` (`;key=value`, Graphite 1.1 tags).
     */
    'dialect' => env('LARAFLOW_STATSD_DIALECT', 'dogstatsd'),

];
