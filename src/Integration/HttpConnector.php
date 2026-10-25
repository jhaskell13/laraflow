<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Integration\Http\ErrorMapper;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\Http\StatusErrorMapper;

/**
 * Base class for connectors that speak HTTP, which is most of them.
 *
 * A subclass describes its provider — base URL, headers, how to
 * authenticate, how it says no — and inherits request(): resolve
 * credentials for this request, authenticate, send through the
 * transport, map the response to an error if it is one. The constructor
 * is satisfied by the container when the registry builds the connector,
 * with the Connection passed by name; a subclass that needs more
 * declares its own constructor and calls parent::__construct().
 *
 * An error response is returned, not thrown, unless the connection sets
 * `throw_on_error` (or throwsOnError() says so): the response carries
 * the mapped exception, reports failed(), and throw() raises it.
 */
abstract class HttpConnector implements Connector
{
    protected HttpOptions $options;

    protected Authenticator $authenticator;

    protected ErrorMapper $errors;

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
        $this->errors = $this->errorMapper();
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

    /**
     * @throws RequestFailedException only when throwsOnError() is true.
     */
    public function request(ConnectorRequest $request): ConnectorResponse
    {
        // Credentials are resolved here, per request, so a rotated token
        // is used by the next call and this cached instance holds none.
        $authenticated = $request->withOptions($this->authenticator->authenticate(
            $request->options,
            $this->credentials->credentials($this->connection),
        ));

        $response = $this->transport()->send($authenticated, $this->options);

        // The mapper sees the request as the caller made it, so nothing
        // the authenticator added can reach an exception message.
        $response = $response->withError($this->errors->map($request, $response, $this->connection));

        if ($this->throwsOnError()) {
            $response->throw();
        }

        return $response;
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
     * How the provider reports errors. By status unless a subclass knows
     * better; see ErrorMapper.
     */
    protected function errorMapper(): ErrorMapper
    {
        return new StatusErrorMapper;
    }

    /**
     * Whether request() throws the mapped error instead of returning the
     * failed response. The connection's `throw_on_error` setting, false
     * by default; a subclass may decide differently.
     */
    protected function throwsOnError(): bool
    {
        return (bool) $this->connection->get('throw_on_error', false);
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
