<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * Decides whether a response is an error, and which kind.
 *
 * Providers disagree about how to say no: most use the status, Slack
 * answers 200 with `"ok": false`, GitHub reports an exhausted rate limit
 * as 403. A connector supplies the mapper that knows its provider; the
 * default goes by status alone.
 */
interface ErrorMapper
{
    /**
     * @param  ConnectorRequest  $request  As the caller made it, before authentication.
     * @return RequestFailedException|null Null when the response is not an error.
     */
    public function map(ConnectorRequest $request, ConnectorResponse $response, Connection $connection): ?RequestFailedException;
}
