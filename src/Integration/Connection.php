<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Laraflow\Exceptions\ConnectorConfigurationException;

/**
 * One named account on one connector: the settings under
 * `laraflow.integrations.{connector}.connections.{name}`.
 *
 * A connector class receives its Connection at construction, so the
 * same class serves every account an application configures — the
 * production GitHub organisation and the staging one, or one tenant's
 * Slack workspace and another's — without a subclass or a closure per
 * account.
 */
readonly class Connection
{
    /**
     * The connection resolved when an application names none and the
     * connector's configuration declares no `default`.
     */
    public const DEFAULT = 'default';

    /**
     * @param  string  $connector  The connector this connection belongs to, e.g. `github`.
     * @param  string  $name  The connection name, e.g. `default` or `enterprise`.
     * @param  array<string, mixed>  $config  The connection's settings.
     */
    public function __construct(
        public string $connector,
        public string $name,
        public array $config = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    /**
     * Read a setting the connector cannot work without.
     *
     * A blank string counts as missing — a declared-but-empty
     * environment variable reaches the config repository as `''`, and
     * sending `Bearer ` to a provider fails less clearly than this does.
     *
     * @param  string|null  $environmentVariable  Named in the error so the fix is one line away.
     *
     * @throws ConnectorConfigurationException
     */
    public function require(string $key, ?string $environmentVariable = null): mixed
    {
        $value = $this->get($key);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw ConnectorConfigurationException::missing($this, $key, $environmentVariable);
        }

        return $value;
    }

    /**
     * The configuration path this connection was read from.
     */
    public function path(): string
    {
        return "laraflow.integrations.{$this->connector}.connections.{$this->name}";
    }

    /**
     * A stable identifier for one connection, suitable for cache keys
     * and log context: `github:default`.
     */
    public function key(): string
    {
        return "{$this->connector}:{$this->name}";
    }
}
