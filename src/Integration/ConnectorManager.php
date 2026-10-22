<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Laraflow\Exceptions\ConnectorNotRegisteredException;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class ConnectorManager
{
    /**
     * @var array<string, Connector>
     */
    protected array $connectors = [];

    public function register(string $name, Connector $connector): void
    {
        $this->connectors[$name] = $connector;
    }

    /**
     * @throws ConnectorNotRegisteredException
     */
    public function get(string $name): Connector
    {
        if (! $this->has($name)) {
            throw ConnectorNotRegisteredException::named($name);
        }

        return $this->connectors[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->connectors[$name]);
    }
}
