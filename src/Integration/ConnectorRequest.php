<?php

declare(strict_types=1);

namespace Laraflow\Integration;

/**
 * An outbound request to an external service, independent of transport.
 */
readonly class ConnectorRequest
{
    /**
     * @param  string  $method  HTTP method, e.g. GET.
     * @param  string  $uri  Path relative to the connector's base URL.
     * @param  array<string, mixed>  $options  Transport options (headers, query, json, ...).
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $options = [],
    ) {}
}
