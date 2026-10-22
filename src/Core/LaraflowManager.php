<?php

declare(strict_types=1);

namespace Laraflow\Core;

use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Exceptions\InvalidConnectorException;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\ConnectorManager;

/**
 * Application-facing entry point for connectors: register lazy factories
 * and resolve (cached) connector instances by name.
 */
class LaraflowManager
{
    public function __construct(
        protected ConnectorFactory $factory,
        protected ConnectorManager $connectors
    ) {}

    /**
     * @param  callable(): Connector  $factory
     */
    public function register(string $name, callable $factory): void
    {
        $this->factory->register($name, $factory);
    }

    /**
     * @throws ConnectorNotRegisteredException
     * @throws InvalidConnectorException
     */
    public function connector(string $name): Connector
    {
        if ($this->connectors->has($name)) {
            return $this->connectors->get($name);
        }

        $connector = $this->factory->make($name);

        $this->connectors->register($name, $connector);

        return $connector;
    }

    public function has(string $name): bool
    {
        return $this->connectors->has($name) || $this->factory->has($name);
    }
}
