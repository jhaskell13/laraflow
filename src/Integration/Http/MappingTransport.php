<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Closure;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use SensitiveParameter;

/**
 * @internal Assembled by TransportPipeline when the connector supplies
 * an error-mapping step.
 *
 * Attaches the connector's mapped error to the response on its way out
 * of the pipeline, between the retry layer and the observing layer:
 * outside retries, so only the response that survives them is mapped,
 * and inside observation, so ConnectorRequestSent.successful reflects
 * a provider that rejected the call inside a 2xx body.
 *
 * The mapping is a closure built by HttpConnector around the request
 * *as the caller made it*: the request this transport receives carries
 * the authenticator's credentials, and nothing here hands it to the
 * mapper.
 */
final class MappingTransport implements HttpTransport
{
    /**
     * @param  Closure(ConnectorResponse): ConnectorResponse  $map
     */
    public function __construct(
        private HttpTransport $inner,
        private Closure $map,
    ) {}

    public function send(#[SensitiveParameter] ConnectorRequest $request, HttpOptions $options): ConnectorResponse
    {
        return ($this->map)($this->inner->send($request, $options));
    }
}
