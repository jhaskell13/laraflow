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

    /**
     * @param  array<string, mixed>  $options  Replaces the current options.
     */
    public function withOptions(array $options): self
    {
        return new self($this->method, $this->uri, $options);
    }

    /**
     * @param  array<string, string>  $headers  Merged over the current headers.
     */
    public function withHeaders(array $headers): self
    {
        $options = $this->options;
        $options['headers'] = array_merge($options['headers'] ?? [], $headers);

        return new self($this->method, $this->uri, $options);
    }
}
