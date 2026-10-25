<?php

declare(strict_types=1);

namespace Laraflow\Core;

use Laraflow\Exceptions\ConnectionNotConfiguredException;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Exceptions\InvalidConnectorException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRegistry;

/**
 * Application-facing entry point for connectors.
 *
 * Register a connector once — as a class resolved through the container,
 * or as a closure — and resolve it per named connection. A connection is
 * one account on one provider; the connector class is shared by all of
 * them. Instances are built on first use and cached per connection.
 */
class LaraflowManager
{
    public function __construct(
        protected ConnectorRegistry $registry,
    ) {}

    /**
     * Register a connector under a name.
     *
     * A class-string is built through the container with the resolved
     * `Connection` passed as `$connection`; a closure receives it as its
     * only argument. Third-party packages call this from their own
     * service provider; applications may instead declare the class under
     * `laraflow.integrations.{name}.connector`.
     *
     * @param  (callable(Connection): Connector)|string  $factory  A closure, or the name of a class implementing Connector — configuration-supplied, so verified at runtime rather than trusted.
     *
     * @throws ConnectorConfigurationException
     * @throws InvalidConnectorException
     */
    public function register(string $name, callable|string $factory): void
    {
        $this->registry->register($name, $factory);
    }

    /**
     * Resolve a connector for a connection — the configured default when
     * none is named.
     *
     * @throws ConnectorNotRegisteredException
     * @throws ConnectionNotConfiguredException
     * @throws ConnectorConfigurationException
     * @throws InvalidConnectorException
     */
    public function connector(string $name, ?string $connection = null): Connector
    {
        return $this->registry->resolve($name, $connection);
    }

    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }

    /**
     * Every registered connector name, sorted.
     *
     * @return list<string>
     */
    public function connectors(): array
    {
        return $this->registry->names();
    }

    /**
     * The connection names configured for a connector.
     *
     * @return list<string>
     *
     * @throws ConnectorNotRegisteredException
     * @throws ConnectorConfigurationException
     */
    public function connections(string $name): array
    {
        return $this->registry->connections($name);
    }

    /**
     * The resolved settings for one connection — the configured default
     * when none is named. Carries no credentials.
     *
     * @throws ConnectorNotRegisteredException
     * @throws ConnectionNotConfiguredException
     * @throws ConnectorConfigurationException
     */
    public function connection(string $name, ?string $connection = null): Connection
    {
        return $this->registry->connection($name, $connection);
    }

    /**
     * Drop cached connector instances so the next resolution rebuilds
     * them: all of them, one connector's, or one connection's.
     */
    public function forget(?string $name = null, ?string $connection = null): void
    {
        $this->registry->forget($name, $connection);
    }
}
