<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use SensitiveParameter;

/**
 * Puts an authenticated request on the wire.
 *
 * The default is Laravel's HTTP client, so `Http::fake()` and its
 * middleware keep working. Bind another implementation to route every
 * connector through something else; override HttpConnector::transport()
 * to do it for one connector.
 */
interface HttpTransport
{
    /**
     * @param  ConnectorRequest  $request  Already authenticated: its options carry credentials, hence the attribute.
     *
     * @throws TransportException when no HTTP response was obtained. An HTTP error status is a response, not an exception.
     */
    public function send(#[SensitiveParameter] ConnectorRequest $request, HttpOptions $options): ConnectorResponse;
}
