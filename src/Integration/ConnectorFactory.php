<?php

namespace Laraflow\Integration;

use InvalidArgumentException;

class ConnectorFactory
{
    protected array $definitions = [];

    public function register(string $name, callable $factory): void
    {
        $this->definitions[$name] = $factory;
    }

    public function make(string $name): Connector
    {
        if (! isset($this->definitions[$name])) {
            throw new InvalidArgumentException("Connector [{$name}] is not registered.");
        }

        $connector = ($this->definitions[$name])();

        if (! $connector instanceof Connector) {
            throw new InvalidArgumentException("Connector factory [{$name}] did not return a factory.");
        }

        return $connector;
    }
}