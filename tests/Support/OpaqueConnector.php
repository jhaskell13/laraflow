<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\HttpConnector;

/**
 * Sends through a transport of its own rather than Laravel's HTTP
 * client, so `Http::fake()` cannot see it. Exists so the published
 * contract harness can be shown to refuse it instead of "passing"
 * every assertion against a connector it never intercepted.
 */
class OpaqueConnector extends HttpConnector
{
    protected function baseUrl(): ?string
    {
        return 'https://opaque.example';
    }

    protected function transport(): HttpTransport
    {
        return new class implements HttpTransport
        {
            public function send(ConnectorRequest $request, HttpOptions $options): ConnectorResponse
            {
                // Stands in for a connector's own Guzzle client: whatever
                // it does, the harness's stub is not involved.
                return ConnectorResponse::fake(200, ['ok' => true]);
            }
        };
    }
}
