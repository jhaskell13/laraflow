<?php

declare(strict_types=1);

namespace Laraflow\Integration;

/**
 * An outbound request to an external service, independent of transport.
 */
readonly class ConnectorRequest
{
    /**
     * Methods HTTP defines as idempotent: repeating one changes nothing
     * a first delivery did not.
     */
    public const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /**
     * @param  string  $method  HTTP method, e.g. GET.
     * @param  string  $uri  Path relative to the connector's base URL.
     * @param  array<string, mixed>  $options  Transport options (headers, query, json, ...).
     * @param  bool|null  $idempotent  Whether repeating this request is safe; null derives it from the method.
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $options = [],
        public ?bool $idempotent = null,
    ) {}

    /**
     * Whether a transport may repeat this request after a failure whose
     * outcome is unknown. By method unless the caller said otherwise —
     * a POST carrying the provider's idempotency key is safe, and says
     * so with asIdempotent().
     */
    public function isIdempotent(): bool
    {
        return $this->idempotent ?? in_array(strtoupper($this->method), self::IDEMPOTENT_METHODS, true);
    }

    public function asIdempotent(bool $idempotent = true): self
    {
        return new self($this->method, $this->uri, $this->options, $idempotent);
    }

    /**
     * @param  array<string, mixed>  $options  Replaces the current options.
     */
    public function withOptions(array $options): self
    {
        return new self($this->method, $this->uri, $options, $this->idempotent);
    }

    /**
     * @param  array<string, string>  $headers  Merged over the current headers.
     */
    public function withHeaders(array $headers): self
    {
        $options = $this->options;
        $options['headers'] = array_merge($options['headers'] ?? [], $headers);

        return new self($this->method, $this->uri, $options, $this->idempotent);
    }
}
