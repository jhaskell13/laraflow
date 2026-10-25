<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Laraflow\Exceptions\ConnectionNotConfiguredException;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Exceptions\InvalidConnectorException;

/**
 * Connector definitions, their configured connections, and the built
 * instances.
 *
 * A definition is either a class implementing Connector — built through
 * the container with the Connection passed as `$connection`, so the
 * class declares its other dependencies as ordinary constructor
 * parameters — or a closure receiving the Connection. Instances are
 * built on first use and cached per connection; forget() drops them.
 *
 * The configuration shape is strict on purpose. A connector section
 * holds `connector`, `default`, and `connections`, nothing else: a
 * `token` left at the connector level (the Milestone 0 layout) is
 * refused with the path it should move to, rather than silently read
 * by nothing. Within a connection, `credentials` is reserved: it is
 * stripped from the Connection and read by the CredentialProvider.
 *
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class ConnectorRegistry
{
    protected const RECOGNISED_KEYS = ['connector', 'default', 'connections'];

    /**
     * @var array<string, Closure(Connection): mixed|class-string<Connector>>
     */
    protected array $definitions = [];

    /**
     * @var array<string, Connector> keyed by Connection::key()
     */
    protected array $instances = [];

    public function __construct(
        protected Container $container,
        protected Config $config,
    ) {}

    /**
     * @param  (callable(Connection): Connector)|string  $factory  A closure, or the name of a class implementing Connector — configuration-supplied, so verified at runtime rather than trusted.
     *
     * @throws ConnectorConfigurationException
     * @throws InvalidConnectorException
     */
    public function register(string $name, callable|string $factory): void
    {
        $this->assertValidName($name, 'connector');

        if (is_string($factory)) {
            if (! class_exists($factory) || ! is_a($factory, Connector::class, true)) {
                throw InvalidConnectorException::forClass($name, $factory);
            }

            $this->definitions[$name] = $factory;
        } else {
            $this->definitions[$name] = $factory(...);
        }

        // Re-registering replaces the definition; instances built from
        // the previous one must not outlive it.
        $this->forget($name);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->definitions);
        sort($names);

        return $names;
    }

    /**
     * The connection names configured for a connector, or the default
     * name alone when the configuration declares none.
     *
     * @return list<string>
     *
     * @throws ConnectorNotRegisteredException
     * @throws ConnectorConfigurationException
     */
    public function connections(string $name): array
    {
        $section = $this->section($name);

        if (! array_key_exists('connections', $section)) {
            return [$this->defaultConnection($name)];
        }

        return array_map('strval', array_keys($section['connections']));
    }

    /**
     * @throws ConnectorNotRegisteredException
     * @throws ConnectorConfigurationException
     */
    public function defaultConnection(string $name): string
    {
        $default = $this->section($name)['default'] ?? null;

        if ($default === null || $default === '') {
            return Connection::DEFAULT;
        }

        if (! is_string($default)) {
            throw new ConnectorConfigurationException(
                "laraflow.integrations.{$name}.default must name a connection."
            );
        }

        return $default;
    }

    /**
     * @throws ConnectorNotRegisteredException
     * @throws ConnectionNotConfiguredException
     * @throws ConnectorConfigurationException
     */
    public function connection(string $name, ?string $connection = null): Connection
    {
        $section = $this->section($name);
        $connection ??= $this->defaultConnection($name);

        $this->assertValidName($connection, 'connection');

        if (! array_key_exists('connections', $section)) {
            // Nothing configured: the default connection exists with no
            // settings, so a closure-registered connector that needs
            // none works without a config section.
            if ($connection !== $this->defaultConnection($name)) {
                throw ConnectionNotConfiguredException::named($name, $connection, []);
            }

            return new Connection($name, $connection);
        }

        $settings = $section['connections'][$connection] ?? null;

        if ($settings === null) {
            throw ConnectionNotConfiguredException::named($name, $connection, $this->connections($name));
        }

        // Credentials never ride on the Connection. They are read by the
        // CredentialProvider when a request is built, so the object a
        // connector holds for its lifetime carries settings only and can
        // be dumped, logged, or listed without redaction.
        unset($settings['credentials']);

        return new Connection($name, $connection, $settings);
    }

    /**
     * @throws ConnectorNotRegisteredException
     * @throws ConnectionNotConfiguredException
     * @throws ConnectorConfigurationException
     * @throws InvalidConnectorException
     */
    public function resolve(string $name, ?string $connection = null): Connector
    {
        $connection = $this->connection($name, $connection);

        return $this->instances[$connection->key()] ??= $this->build($connection);
    }

    /**
     * Drop built instances: every one, every connection of one
     * connector, or one connection.
     */
    public function forget(?string $name = null, ?string $connection = null): void
    {
        if ($name === null) {
            $this->instances = [];

            return;
        }

        if ($connection !== null) {
            unset($this->instances["{$name}:{$connection}"]);

            return;
        }

        foreach (array_keys($this->instances) as $key) {
            if (str_starts_with($key, "{$name}:")) {
                unset($this->instances[$key]);
            }
        }
    }

    protected function build(Connection $connection): Connector
    {
        $definition = $this->definitions[$connection->connector];

        $connector = $definition instanceof Closure
            ? $definition($connection)
            : $this->container->make($definition, ['connection' => $connection]);

        if (! $connector instanceof Connector) {
            throw InvalidConnectorException::forFactory($connection->connector, $connector);
        }

        return $connector;
    }

    /**
     * The validated `laraflow.integrations.{name}` section, or an empty
     * array when the connector has none.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectorNotRegisteredException
     * @throws ConnectorConfigurationException
     */
    protected function section(string $name): array
    {
        if (! $this->has($name)) {
            throw ConnectorNotRegisteredException::named($name);
        }

        $section = $this->config->get("laraflow.integrations.{$name}");

        if ($section === null) {
            return [];
        }

        if (! is_array($section)) {
            throw new ConnectorConfigurationException("laraflow.integrations.{$name} must be an array.");
        }

        foreach (array_keys($section) as $key) {
            if (! in_array((string) $key, self::RECOGNISED_KEYS, true)) {
                $default = $section['default'] ?? Connection::DEFAULT;

                throw ConnectorConfigurationException::unexpectedKey(
                    $name,
                    (string) $key,
                    self::RECOGNISED_KEYS,
                    is_string($default) && $default !== '' ? $default : Connection::DEFAULT,
                );
            }
        }

        if (array_key_exists('connections', $section)) {
            if (! is_array($section['connections'])) {
                throw ConnectorConfigurationException::malformedConnections($name);
            }

            foreach ($section['connections'] as $settings) {
                if (! is_array($settings)) {
                    throw ConnectorConfigurationException::malformedConnections($name);
                }
            }
        }

        return $section;
    }

    protected function assertValidName(string $name, string $kind): void
    {
        if ($name === '' || preg_match('/[\s\x00-\x1F\x7F]/', $name) === 1) {
            throw ConnectorConfigurationException::invalidName($name, $kind);
        }
    }
}
