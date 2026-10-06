<?php
namespace Laraflow\Integration;

interface Connector
{
    public function name(): string;

    public function request(ConnectorRequest $request): ConnectorResponse;
}