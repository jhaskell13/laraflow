<?php

namespace Laraflow\Integration\Github;

use Illuminate\Support\Facades\Http;
use Laraflow\Auth\Authenticator;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

class GithubConnector implements Connector
{
    public function __construct(protected Authenticator $authenticator) {}

    public function name(): string
    {
        return 'github';
    }

    public function request(ConnectorRequest $request): ConnectorResponse {
        $options = $this->authenticator->authenticate($request->options);

        $response = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->send(
                $request->method,
                $request->uri,
                $options
            );

        return new ConnectorResponse(
            status: $response->status(),
            headers: $response->headers(),
            body: $response->json()
        );
    }
}