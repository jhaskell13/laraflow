<?php

declare(strict_types=1);

namespace Laraflow\Integration\Github;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Laraflow\Auth\Authenticator;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * @experimental Prototype connector; HTTP behaviour is not yet configurable.
 */
class GithubConnector implements Connector
{
    public const BASE_URL = 'https://api.github.com';

    protected Authenticator $authenticator;

    public function __construct(
        protected Connection $connection,
        protected HttpClient $http,
    ) {
        $token = $connection->require('token', 'LARAFLOW_GITHUB_TOKEN');

        $this->authenticator = new GithubTokenAuthenticator((string) $token);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function name(): string
    {
        return 'github';
    }

    public function request(ConnectorRequest $request): ConnectorResponse
    {
        $options = $this->authenticator->authenticate($request->options);

        try {
            $response = $this->http
                ->baseUrl(self::BASE_URL)
                ->acceptJson()
                ->send($request->method, $request->uri, $options);
        } catch (ConnectionException $exception) {
            // Deliberately omits request options: they carry credentials.
            throw new TransportException(
                message: 'Unable to connect to GitHub.',
                previous: $exception,
            );
        }

        return new ConnectorResponse(
            status: $response->status(),
            headers: $response->headers(),
            body: $response->json(),
        );
    }
}
