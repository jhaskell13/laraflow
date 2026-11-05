<?php

declare(strict_types=1);

/*
 * laraflow/pulse. Nothing here takes effect until `enabled` is true:
 * installing the bridge and doing nothing else changes nothing
 * (docs/metrics.md, "What every bridge must do").
 */
return [

    'enabled' => (bool) env('LARAFLOW_PULSE_ENABLED', false),

    /*
     * Add the `tenant` label to every execution, step, compensation, and
     * connector series. Off by default: every tenant multiplies the rows
     * Pulse aggregates (docs/metrics.md).
     */
    'tenant_label' => (bool) env('LARAFLOW_PULSE_TENANT_LABEL', false),

    // Seconds between snapshots taken from `pulse:check`'s heartbeat.
    'gauge_interval' => (int) env('LARAFLOW_PULSE_GAUGE_INTERVAL', 60),

];
