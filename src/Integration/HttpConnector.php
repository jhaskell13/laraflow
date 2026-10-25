<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;

/**
 * Base class for connectors that speak HTTP, which is most of them.
 *
 * A subclass describes its provider — base URL, headers, how to
 * authenticate — and inherits request(): resolve credentials for this
 * request, authenticate, send through the transport. The constructor is
 * satisfied by the container when the registry builds the connector,
 * with the Connection passed by name; a subclass that needs more
 * declares its own constructor and calls parent::__construct().
 */
abstract class HttpConnector implements Connector
{
    protected HttpOptions $options;

    protected Authenticator $authenticator;

    public function __construct(
        protected Connection $connection,
        protected CredentialProvider $credentials,
        protected HttpTransport $transport,
        HttpOptions $defaults,
    ) {
        // Class defaults first, then the connection's overrides, so a
        // connection can point the same class at another host.
        $this->options = $defaults
            ->withBaseUrl($this->baseUrl())
            ->withHeaders($this->headers())
            ->forConnection($connection);

        $this->authenticator = $this->authenticator();
    }

    /**
     * The registered name by default. Override when a class serves a
     * provider under a name that differs from its configuration key.
     */
    public function name(): string
    {
        return $this->connection->connector;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function request(ConnectorRequest $request): ConnectorResponse
    {
        // Credentials are resolved here, per request, so a rotated token
        // is used by the next call and this cached instance holds none.
        $authenticated = $request->withOptions($this->authenticator->authenticate(
            $request->options,
            $this->credentials->credentials($this->connection),
        ));

        return $this->transport()->send($authenticated, $this->options);
    }

    /**
     * The provider's API root. A connection's `base_url` overrides it.
     */
    abstract protected function baseUrl(): ?string;

    /**
     * Headers every request carries. A connection's `headers` are merged
     * over these; a request's own headers win over both.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    /**
     * How requests are authenticated. Bearer token from the `token`
     * credential unless a subclass says otherwise.
     */
    protected function authenticator(): Authenticator
    {
        return new BearerTokenAuthenticator;
    }

    /**
     * The transport in use. Override to route one connector through
     * something other than the container's binding.
     */
    protected function transport(): HttpTransport
    {
        return $this->transport;
    }
}
