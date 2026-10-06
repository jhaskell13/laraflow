<?php
namespace Laraflow\Integration;

use Illuminate\Http\Client\Response;

interface Connector
{
    public function name(): string;

    public function request(
        string $method,
        string $uri,
        array $options = []
    ): ?Response;

}