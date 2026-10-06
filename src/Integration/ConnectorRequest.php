<?php

namespace Laraflow\Integration;

class ConnectorRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $options = [],
    ) {
    }
}