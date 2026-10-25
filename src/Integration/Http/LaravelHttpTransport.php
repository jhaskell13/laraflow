<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

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
 * the request winning — and handed over in one options array, rather
 * than split between the pending request and send(). The client's own
 * merge is recursive and turns two values for one header into a list.
 *
 * @internal Default binding. Swap via the container if needed.
 */
class LaravelHttpTransport implements HttpTransport
{
    public function __construct(protected HttpClient $http) {}

    public function send(#[SensitiveParameter] ConnectorRequest $request, HttpOptions $options): ConnectorResponse
    {
        $requestOptions = $request->options;
        $requestOptions['headers'] = array_merge($options->headers, $requestOptions['headers'] ?? []);

        $pending = $this->http
            ->timeout($options->timeoutSeconds)
            ->connectTimeout($options->connectTimeoutSeconds);

        if ($options->baseUrl !== null) {
            $pending = $pending->baseUrl($options->baseUrl);
        }

        try {
            $response = $pending->send($request->method, $request->uri, $requestOptions);
        } catch (ConnectionException $exception) {
            // The host only: the request options carry credentials, and
            // the client's own message may quote the URL with its query.
            throw TransportException::connecting($options->host() ?? $request->uri, $exception);
        }

        return new ConnectorResponse(
            status: $response->status(),
            headers: $response->headers(),
            raw: $response->body(),
        );
    }
}
