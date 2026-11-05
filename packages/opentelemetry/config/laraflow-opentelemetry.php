<?php

declare(strict_types=1);

/*
 * laraflow/opentelemetry. Nothing here takes effect until `enabled` is
 * true, and nothing is exported until the application configures an
 * OpenTelemetry SDK and exporter (docs/tracing.md).
 */
return [

    'enabled' => (bool) env('LARAFLOW_OPENTELEMETRY_ENABLED', false),

    /*
     * Seconds after an execution's creation during which its runs join
     * the trace it was started in. A run after that starts a new trace
     * linked to the original, so an execution resumed days later does
     * not stretch one trace across those days (docs/tracing.md).
     */
    'link_after' => (int) env('LARAFLOW_OPENTELEMETRY_LINK_AFTER', 3600),

];
