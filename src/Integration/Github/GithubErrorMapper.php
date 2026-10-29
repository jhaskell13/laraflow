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
 * GitHub reports an exhausted primary rate limit as 403 with
 * `X-RateLimit-Remaining: 0`, and its secondary (abuse) limit as 403
 * with a `Retry-After` header and a non-zero remaining count. By status
 * alone both would read as authentication failures and be retried
 * never rather than later — and ignoring the secondary limit's wait is
 * precisely when GitHub escalates the ban.
 *
 * @experimental With the connector it belongs to.
 */
class GithubErrorMapper extends StatusErrorMapper
{
    public function map(ConnectorRequest $request, ConnectorResponse $response, Connection $connection): ?RequestFailedException
    {
        $rateLimited = $response->status() === 403
            && ($response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After') !== null);

        if ($rateLimited) {
            return RateLimitedException::fromResponse($request, $response, $connection, $this->detail($response));
        }

        return parent::map($request, $response, $connection);
    }
}
