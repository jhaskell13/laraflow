<?php

namespace Laraflow\Integration\Github;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laraflow\Auth\Authenticator;
use Laraflow\Exceptions\TransportException;
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

        try {
            $response = Http::baseUrl('https://api.github.com')
                ->acceptJson()
                ->send(
                    $request->method,
                    $request->uri,
                    $options
                );
        } catch (ConnectionException $exception) {
            throw new TransportException(
                message: 'Unable to connect to Github.',
                previous: $exception,
            );
        }

        return new ConnectorResponse(
            status: $response->status(),
            headers: $response->headers(),
            body: $response->json()
        );
    }
}