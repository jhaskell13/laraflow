<?php

declare(strict_types=1);

namespace Laraflow\Integration;

interface Connector
{
    public function name(): string;

    public function request(ConnectorRequest $request): ConnectorResponse;
}
