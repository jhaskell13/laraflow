<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Generator;
use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Exceptions\Connector\PaginationException;
use Laraflow\Exceptions\Connector\RequestFailedException;
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

        $response = $this->pipeline
            ->through($this->transport(), $this->options, $this->connection)
            ->send($authenticated, $this->options);

        // The mapper sees the request as the caller made it, so nothing
        // the authenticator added can reach an exception message.
        $response = $response->withError($this->errors->map($request, $response, $this->connection));

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
     * @return Generator<int, ConnectorResponse>
     *
     * @throws PaginationException
     * @throws RequestFailedException
     */
    public function pages(ConnectorRequest $request, ?int $maxPages = null): Generator
    {
        $paginator = $this->paginator() ?? throw PaginationException::notPaginated($this->connection);
        $limit = $maxPages ?? $this->options->maxPages;
        $page = 0;

        while (true) {
            $response = $this->request($request)->throw();
            $page++;

            yield $response;

            $next = $paginator->next($request, $response);

            if ($next === null) {
                return;
            }

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
     */
    public function items(ConnectorRequest $request, ?int $maxPages = null): Generator
    {
        $paginator = $this->paginator() ?? throw PaginationException::notPaginated($this->connection);

        foreach ($this->pages($request, $maxPages) as $page) {
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
        $host = parse_url($request->uri, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return;
        }

        $expected = $this->options->host();

        if ($expected !== null && strcasecmp($host, $expected) !== 0) {
            throw PaginationException::foreignHost($this->connection, $expected, $host);
        }
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
}
