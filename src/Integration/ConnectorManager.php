<?php

namespace Laraflow\Integration;

use InvalidArgumentException;

class ConnectorManager
{

    protected array $connectors = [];

    public function register(
        string $name,
        Connector $connector
    ): void {
        $this->connectors[$name] = $connector;
    }

    public function get(string $name): Connector
    {
        if (! isset($this->connectors[$name])) {
            throw new InvalidArgumentException("Connector [{$name}] is not registered.");
        }

        return $this->connectors[$name];
    }

}