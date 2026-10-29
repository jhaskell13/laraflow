<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Generator;
use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Exceptions\Connector\PaginationException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Exceptions\Connector\ThrottledException;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\Http\ErrorMapper;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\Http\StatusErrorMapper;
use Laraflow\Integration\Http\TransportPipeline;
use Laraflow\Integration\Pagination\Paginator;

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

    /**
     * The provider API version in use, if the provider has versions.
     */
    protected ?string $apiVersion;

    public function __construct(
        protected Connection $connection,
        protected CredentialProvider $credentials,
        protected HttpTransport $transport,
        HttpOptions $defaults,
        protected TransportPipeline $pipeline,
    ) {
        // Class defaults first, then the connection's overrides, so a
        // connection can point the same class at another host.
        $this->options = $defaults
            ->withBaseUrl($this->baseUrl())
            ->withHeaders($this->headers())
            ->forConnection($connection);

        // The connection may pin another version of the provider's API
        // than the class was written against — to move early onto a new
        // one, or to stay on an old one while an application catches up.
        $pinned = $connection->get('api_version');
        $this->apiVersion = is_string($pinned) && trim($pinned) !== '' ? $pinned : $this->apiVersion();

        $header = $this->apiVersionHeader();

        if ($this->apiVersion !== null && $header !== null) {
            // Under the connection's own headers: an explicit header
            // beats an implicit one.
            $this->options = $this->options->withHeaders([$header => $this->apiVersion]);
        }

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
     * The HTTP behaviour this connector resolved for its connection.
     * Carries no credentials; `laraflow:connectors` displays it.
     */
    public function options(): HttpOptions
    {
        return $this->options;
    }

    /**
     * The provider API version requests are made against: the
     * connection's `api_version` if pinned, else the version this class
     * was written for, else null for a provider without versions.
     */
    public function providerApiVersion(): ?string
    {
        return $this->apiVersion;
    }

    /**
     * @throws TransportException when no HTTP response was obtained.
     * @throws ThrottledException when this connection's own rate limit refused to send.
     * @throws ConnectorConfigurationException when a required credential is missing.
     * @throws RequestFailedException when the provider answered with an error — only if throwsOnError().
     */
    public function request(ConnectorRequest $request): ConnectorResponse
    {
        // Credentials are resolved here, per request, so a rotated token
        // is used by the next call and this cached instance holds none.
        $authenticated = $request->withOptions($this->authenticator->authenticate(
            $request->options,
            $this->credentials->credentials($this->connection),
        ));

        // Mapping happens inside the pipeline — under the observing
        // layer, so ConnectorRequestSent.successful reflects a provider
        // that rejected the call inside a 2xx body. The closure holds
        // the request as the caller made it, so nothing the
        // authenticator added can reach the mapper or an exception
        // message.
        $response = $this->pipeline
            ->through(
                $this->transport(),
                $this->options,
                $this->connection,
                fn (ConnectorResponse $response): ConnectorResponse => $response
                    ->withError($this->errors->map($request, $response, $this->connection)),
            )
            ->send($authenticated, $this->options);

        if ($this->throwsOnError()) {
            $response->throw();
        }

        return $response;
    }

    /**
     * Every page of a paginated request, fetched one at a time.
     *
     * A generator, so a caller that stops early fetches no more, and a
     * long result set never exists in memory at once. Each page is
     * thrown on failure: paging past an error would silently return a
     * short list.
     *
     * The page budget (`maxPages`, a connection's `max_pages`, or
     * `laraflow.http.pagination.max_pages`) is a **refusal**, not a
     * quiet stop — see PaginationException::limitReached().
     *
     * $paginator overrides the connector's own, for providers whose
     * result shape differs per endpoint.
     *
     * @return Generator<int, ConnectorResponse>
     *
     * @throws PaginationException
     * @throws RequestFailedException
     * @throws TransportException
     */
    public function pages(ConnectorRequest $request, ?int $maxPages = null, ?Paginator $paginator = null): Generator
    {
        // Not a generator itself, so a connector that cannot paginate and
        // a budget that cannot be met are refused when pages() is called
        // rather than when iteration first happens to begin.
        $paginator ??= $this->paginator() ?? throw PaginationException::notPaginated($this->connection);
        $limit = $maxPages ?? $this->options->maxPages;

        if ($limit < 1) {
            throw PaginationException::invalidLimit($this->connection, $limit);
        }

        return $this->pagesFrom($request, $limit, $paginator);
    }

    /**
     * Every item of every page, flattened, one at a time.
     *
     * Do not collect these into a step's output: a result set large
     * enough to need paging is large enough to meet
     * `laraflow.execution.max_payload_bytes`. Handle each item, or
     * store identifiers.
     *
     * @return Generator<int, mixed>
     *
     * @throws PaginationException
     * @throws RequestFailedException
     * @throws TransportException
     */
    public function items(ConnectorRequest $request, ?int $maxPages = null, ?Paginator $paginator = null): Generator
    {
        $paginator ??= $this->paginator() ?? throw PaginationException::notPaginated($this->connection);

        foreach ($this->pages($request, $maxPages, $paginator) as $page) {
            // Yielded one at a time rather than `yield from`, which
            // would repeat each page's own keys: a caller doing
            // iterator_to_array() would then keep only the last page's
            // worth, silently.
            foreach ($paginator->items($page) as $item) {
                yield $item;
            }
        }
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
     * The provider API version this connector was written against, or
     * null for a provider that does not version its API. Declaring it
     * is how a connector states what it was tested against; see
     * docs/connectors.md on compatibility expectations.
     */
    protected function apiVersion(): ?string
    {
        return null;
    }

    /**
     * The header the version is sent in, when the provider selects
     * versions by header (`X-GitHub-Api-Version`, `Stripe-Version`).
     * Null when the version lives in the URL or is not sent at all; the
     * connector then puts it in baseUrl() itself.
     */
    protected function apiVersionHeader(): ?string
    {
        return null;
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
     * How the provider says there is more. Null — the default — means
     * pages() and items() are not available on this connector.
     */
    protected function paginator(): ?Paginator
    {
        return null;
    }

    /**
     * A next-page link may be absolute, and the transport sends an
     * absolute URL as given rather than against the base URL. The
     * request carries an Authorization header, so a link to a host this
     * connection never named is refused rather than followed.
     *
     * @throws PaginationException
     */
    protected function assertSameHost(ConnectorRequest $request): void
    {
        // A link that does not parse at all is refused, not handed to
        // the transport to make sense of: the credential travels with it.
        if (parse_url($request->uri) === false) {
            throw PaginationException::unverifiableHost($this->connection, $request->uri);
        }

        $host = parse_url($request->uri, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return;
        }

        $expected = $this->options->host();

        // No base URL means no host to hold the link to. The link is
        // refused rather than followed: an unverifiable absolute URI is
        // the case the guard exists for, not an exemption from it.
        if ($expected === null) {
            throw PaginationException::unverifiableHost($this->connection, $host);
        }

        if (strcasecmp($host, $expected) !== 0) {
            throw PaginationException::foreignHost($this->connection, $expected, $host);
        }

        // Same hostname is not enough. A link downgraded to http would
        // put the Authorization header on the wire in clear text, and a
        // different port is a different service that happens to share a
        // name; both are refused like any other foreign destination.
        $base = (string) $this->options->baseUrl;
        $baseScheme = strtolower((string) (parse_url($base, PHP_URL_SCHEME) ?: 'https'));
        $linkScheme = strtolower((string) (parse_url($request->uri, PHP_URL_SCHEME) ?: $baseScheme));

        $defaultPort = fn (string $scheme): int => $scheme === 'http' ? 80 : 443;
        $basePort = parse_url($base, PHP_URL_PORT) ?: $defaultPort($baseScheme);
        $linkPort = parse_url($request->uri, PHP_URL_PORT) ?: $defaultPort($linkScheme);

        if ($linkScheme !== $baseScheme || $linkPort !== $basePort) {
            throw PaginationException::foreignHost(
                $this->connection,
                "{$baseScheme}://{$expected}:{$basePort}",
                "{$linkScheme}://{$host}:{$linkPort}",
            );
        }
    }

    /**
     * A root-relative next-page link (`/api/v3/repos?page=2`) resolves
     * against the base URL's *origin*, not its path — the transport
     * would otherwise prefix the whole base URL and double the path.
     * Anything else is sent as given.
     */
    protected function absolute(ConnectorRequest $request): ConnectorRequest
    {
        if (! str_starts_with($request->uri, '/') || str_starts_with($request->uri, '//') || $this->options->baseUrl === null) {
            return $request;
        }

        $base = $this->options->baseUrl;
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);

        if (! is_string($scheme) || ! is_string($host)) {
            return $request;
        }

        $origin = "{$scheme}://{$host}".($port !== null ? ":{$port}" : '');

        return new ConnectorRequest($request->method, $origin.$request->uri, $request->options, $request->idempotent);
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
     * The base transport. Override to route one connector through
     * something other than the container's binding; the retry and
     * rate-limit behaviour the options enable is layered on top of
     * whatever this returns.
     */
    protected function transport(): HttpTransport
    {
        return $this->transport;
    }

    /**
     * @return Generator<int, ConnectorResponse>
     *
     * @throws PaginationException
     * @throws RequestFailedException
     * @throws TransportException
     */
    private function pagesFrom(ConnectorRequest $request, int $limit, Paginator $paginator): Generator
    {
        $page = 0;

        while (true) {
            $response = $this->request($request)->throw();
            $page++;

            yield $response;

            $next = $paginator->next($request, $response);

            if ($next === null) {
                return;
            }

            $next = $this->absolute($next);
            $this->assertSameHost($next);

            if ($next->method === $request->method && $next->uri === $request->uri && $next->options === $request->options) {
                throw PaginationException::notAdvancing($this->connection, $page);
            }

            if ($page >= $limit) {
                throw PaginationException::limitReached($this->connection, $limit);
            }

            $request = $next;
        }
    }
}
