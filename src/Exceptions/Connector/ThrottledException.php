<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

use DateTimeInterface;
use Laraflow\Exceptions\ConnectorException;
use Laraflow\Exceptions\SuggestsRetryDelay;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Http\RateLimitPolicy;

/**
 * Laraflow's own rate limit refused the request before it was sent, and
 * the wait was longer than the connection allows in-process. No request
 * reached the provider.
 *
 * Suggests the wait, so a step that fails with it is retried durably
 * when a slot is due rather than holding a worker for one.
 */
class ThrottledException extends ConnectorException implements SuggestsRetryDelay
{
    public function __construct(string $message, public readonly int $availableInSeconds)
    {
        parent::__construct($message);
    }

    public static function for(Connection $connection, RateLimitPolicy $policy, int $availableInSeconds): self
    {
        return new self(sprintf(
            'Connector [%s] is throttled by its rate limit of %d per %d seconds; the next slot is in %d seconds.',
            $connection->key(),
            $policy->maxRequests,
            $policy->perSeconds,
            $availableInSeconds,
        ), $availableInSeconds);
    }

    public function suggestedRetryDelaySeconds(DateTimeInterface $now): ?int
    {
        return $this->availableInSeconds;
    }
}
