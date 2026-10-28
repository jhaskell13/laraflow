<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Closure;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use SensitiveParameter;

/**
 * @internal Applies the connector's error mapping inside the pipeline.
 *
 * Sits just inside ObservingTransport and outside the retries: the
 * retry policy judges raw responses, and the response that finally
 * comes back is mapped here, so the observer announcing the call sees
 * the error the connector found — a `200` the provider rejected in its
 * body is announced as unsuccessful, not as the status alone suggests.
 *
 * The mapping is a closure the connector supplies rather than the
 * mapper itself, because the mapper must see the request as the caller
 * made it, before authentication, and this layer only ever sees the
 * authenticated one.
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
