<?php

namespace Laraflow\Core;

use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\ConnectorManager;

class LaraflowManager
{
    public function __construct(
        protected ConnectorFactory $factory,
        protected ConnectorManager $connectors
    ) {

    }

    public function register(
        string $name,
        callable $factory
    ): void {
        $this->factory->register($name, $factory);
    }

    public function connector(string $name): Connector
    {
        if ($this->connectors->has($name)) {
            return $this->connectors->get($name);
        }

        $connector = $this->factory->make($name);

        $this->connectors->register($name, $connector);

        return $connector;
    }
}