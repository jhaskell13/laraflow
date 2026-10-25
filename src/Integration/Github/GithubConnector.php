<?php

declare(strict_types=1);

namespace Laraflow\Integration\Github;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\CredentialProvider;

/**
 * @experimental Prototype connector; HTTP behaviour is not yet configurable.
 */
class GithubConnector implements Connector
{
    public const BASE_URL = 'https://api.github.com';

    protected Authenticator $authenticator;

    public function __construct(
        protected Connection $connection,
        protected CredentialProvider $credentials,
        protected HttpClient $http,
    ) {
        $this->authenticator = new BearerTokenAuthenticator('token', 'LARAFLOW_GITHUB_TOKEN');
    }

    public function name(): string
    {
        return 'github';
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function request(ConnectorRequest $request): ConnectorResponse
    {
        // Credentials are resolved here, per request, so a rotated token
        // is used by the next call and this cached instance holds none.
        $options = $this->authenticator->authenticate(
            $request->options,
            $this->credentials->credentials($this->connection),
        );

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
