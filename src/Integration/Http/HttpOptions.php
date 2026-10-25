<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Integration\Connection;

/**
 * How one connection speaks HTTP: where, how long to wait, and which
 * headers every request carries.
 *
 * Resolved connector → connection → `laraflow.http` defaults, so a
 * connector class states what its provider needs, a connection
 * overrides it for one account (an enterprise host, a slower region),
 * and an application sets fleet-wide limits once.
 */
final readonly class HttpOptions
{
    /**
     * @param  string|null  $baseUrl  Prefixed to every request URI; null sends URIs as given.
     * @param  float  $timeoutSeconds  Whole-request bound, connection included.
     * @param  float  $connectTimeoutSeconds  Bound on establishing the connection alone.
     * @param  array<string, string>  $headers  Sent with every request; a request's own headers win.
     * @param  TransportPolicy  $retries  In-attempt wire retries; disabled unless configured.
     * @param  RateLimitPolicy|null  $rateLimit  A ceiling on wire attempts per connection; none unless configured.
     */
    public function __construct(
        public ?string $baseUrl = null,
        public float $timeoutSeconds = 30.0,
        public float $connectTimeoutSeconds = 10.0,
        public array $headers = [],
        public TransportPolicy $retries = new TransportPolicy,
        public ?RateLimitPolicy $rateLimit = null,
    ) {
        if ($timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('The HTTP timeout must be greater than zero seconds.');
        }

        if ($connectTimeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('The HTTP connect timeout must be greater than zero seconds.');
        }

        if ($baseUrl !== null && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("The HTTP base URL [{$baseUrl}] is not a valid URL.");
        }
    }

    /**
     * The fleet-wide defaults from `laraflow.http`, a blank environment
     * value meaning "use the documented default".
     */
    public static function fromConfig(Config $config): self
    {
        $setting = static function (string $key) use ($config): mixed {
            $value = $config->get("laraflow.http.{$key}");

            return $value === '' ? null : $value;
        };

        $retries = $config->get('laraflow.http.retries', []);
        $rateLimit = $config->get('laraflow.http.rate_limit');

        return new self(
            timeoutSeconds: (float) ($setting('timeout') ?? 30.0),
            connectTimeoutSeconds: (float) ($setting('connect_timeout') ?? 10.0),
            retries: TransportPolicy::fromArray(is_array($retries) ? $retries : []),
            rateLimit: RateLimitPolicy::fromArray(is_array($rateLimit) ? $rateLimit : null),
        );
    }

    /**
     * These options overridden by whatever the connection configures:
     * `base_url`, `timeout`, `connect_timeout`, `headers`, `retries`,
     * `rate_limit`.
     *
     * @throws ConnectorConfigurationException
     */
    public function forConnection(Connection $connection): self
    {
        $headers = $connection->get('headers', []);
        $retries = $connection->get('retries', []);
        $rateLimit = $connection->get('rate_limit');

        if (! is_array($headers)) {
            throw new ConnectorConfigurationException("{$connection->path()}.headers must be an array of header name => value.");
        }

        if (! is_array($retries)) {
            throw new ConnectorConfigurationException("{$connection->path()}.retries must be an array of retry settings.");
        }

        if ($rateLimit !== null && ! is_array($rateLimit)) {
            throw new ConnectorConfigurationException("{$connection->path()}.rate_limit must be an array with max, per, and max_wait.");
        }

        try {
            return new self(
                baseUrl: self::stringOrNull($connection->get('base_url')) ?? $this->baseUrl,
                timeoutSeconds: self::floatOrNull($connection->get('timeout')) ?? $this->timeoutSeconds,
                connectTimeoutSeconds: self::floatOrNull($connection->get('connect_timeout')) ?? $this->connectTimeoutSeconds,
                headers: array_merge($this->headers, array_map('strval', $headers)),
                retries: TransportPolicy::fromArray($retries, $this->retries),
                rateLimit: RateLimitPolicy::fromArray($rateLimit, $this->rateLimit),
            );
        } catch (InvalidArgumentException $exception) {
            throw new ConnectorConfigurationException("{$connection->path()}: {$exception->getMessage()}", previous: $exception);
        }
    }

    public function withBaseUrl(?string $baseUrl): self
    {
        return new self($baseUrl, $this->timeoutSeconds, $this->connectTimeoutSeconds, $this->headers, $this->retries, $this->rateLimit);
    }

    /**
     * @param  array<string, string>  $headers  Merged under the existing ones.
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->baseUrl, $this->timeoutSeconds, $this->connectTimeoutSeconds, array_merge($headers, $this->headers), $this->retries, $this->rateLimit);
    }

    public function withRetries(TransportPolicy $retries): self
    {
        return new self($this->baseUrl, $this->timeoutSeconds, $this->connectTimeoutSeconds, $this->headers, $retries, $this->rateLimit);
    }

    public function withRateLimit(?RateLimitPolicy $rateLimit): self
    {
        return new self($this->baseUrl, $this->timeoutSeconds, $this->connectTimeoutSeconds, $this->headers, $this->retries, $rateLimit);
    }

    /**
     * The host requests go to — safe for an error message, carrying no
     * path, query, or userinfo.
     */
    public function host(): ?string
    {
        if ($this->baseUrl === null) {
            return null;
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
