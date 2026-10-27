<?php

declare(strict_types=1);

namespace Laraflow\Integration\Github;

use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\StatusErrorMapper;

/**
 * GitHub reports an exhausted rate limit as 403 with
 * `X-RateLimit-Remaining: 0`, which by status alone would read as an
 * authentication failure and be retried never rather than later.
 *
 * @experimental With the connector it belongs to.
 */
class GithubErrorMapper extends StatusErrorMapper
{
    public function map(ConnectorRequest $request, ConnectorResponse $response, Connection $connection): ?RequestFailedException
    {
        if ($response->status() === 403 && $response->header('X-RateLimit-Remaining') === '0') {
            return RateLimitedException::fromResponse($request, $response, $connection, $this->detail($response));
        }

        return parent::map($request, $response, $connection);
    }
}
