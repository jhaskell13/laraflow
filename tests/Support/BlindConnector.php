<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\ErrorMapper;
use Laraflow\Integration\HttpConnector;

/**
 * Treats every response as a success. Exists so the published contract
 * harness can be shown to catch a connector that does not classify
 * errors.
 */
class BlindConnector extends HttpConnector
{
    protected function baseUrl(): ?string
    {
        return 'https://blind.example';
    }

    protected function errorMapper(): ErrorMapper
    {
        return new class implements ErrorMapper
        {
            public function map(ConnectorRequest $request, ConnectorResponse $response, Connection $connection): ?RequestFailedException
            {
                return null;
            }
        };
    }
}
