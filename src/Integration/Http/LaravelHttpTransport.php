<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use SensitiveParameter;

/**
 * Default transport: Laravel's HTTP client.
 *
 * Headers are merged here — connection defaults under the request's own,
 * the request winning, names matched case-insensitively — and handed
 * over in one options array, rather than split between the pending
 * request and send(). The client's own merge is recursive, case
 * sensitive, and turns two values for one header into a list.
 *
 * @internal Default binding. Swap via the container if needed.
 */
class LaravelHttpTransport implements HttpTransport
{
    public function __construct(protected HttpClient $http) {}

    public function send(#[SensitiveParameter] ConnectorRequest $request, HttpOptions $options): ConnectorResponse
    {
        $requestOptions = $request->options;
        $requestOptions['headers'] = Headers::merge($options->headers, $requestOptions['headers'] ?? []);

        $pending = $this->http
            ->timeout($options->timeoutSeconds)
            ->connectTimeout($options->connectTimeoutSeconds);

        if ($options->baseUrl !== null) {
            $pending = $pending->baseUrl($options->baseUrl);
        }

        try {
            $response = $pending->send($request->method, $request->uri, $requestOptions);
        } catch (ConnectionException|TransferException $exception) {
            // The host only: the request options carry credentials, and
            // the client's own message may quote the URL with its query.
            // Guzzle's own transfer failures (too many redirects, a
            // malformed response) are caught too: left alone they would
            // surface untyped, with the URL in their message.
            throw TransportException::connecting($options->host() ?? self::hostOf($request->uri), $exception);
        }

        return new ConnectorResponse(
            status: $response->status(),
            headers: $response->headers(),
            raw: $response->body(),
        );
    }

    /**
     * The host of an absolute request URI, for a connector with no base
     * URL. Never the URI itself: its query string may carry a token.
     */
    protected static function hostOf(string $uri): string
    {
        $host = parse_url($uri, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the provider';
    }
}
