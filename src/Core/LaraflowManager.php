<?php

namespace Laraflow\Core;

use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorManager;

class LaraflowManager
{
    public function __construct(protected ConnectorManager $connectors) {}

    public function connector(string $name): Connector
    {
        return $this->connectors->get($name);
    }
}