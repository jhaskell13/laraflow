<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

use Laraflow\Exceptions\ConnectorException;
use Laraflow\Integration\Connection;

/**
 * Paging through a provider's results could not continue safely.
 *
 * Every case here is deliberately loud. Stopping quietly would hand the
 * caller a short list that looks complete, which is the one outcome a
 * paginator must never produce.
 */
class PaginationException extends ConnectorException
{
    public static function limitReached(Connection $connection, int $maxPages): self
    {
        return new self(sprintf(
            'Connector [%s] stopped after %d pages, the configured maximum. '
            .'The result set is incomplete: raise laraflow.http.pagination.max_pages, '
            .'pass a larger maxPages, or narrow the request.',
            $connection->key(),
            $maxPages,
        ));
    }

    public static function notPaginated(Connection $connection): self
    {
        return new self(sprintf(
            'Connector [%s] declares no paginator, so its results cannot be paged. '
            .'Override paginator() on the connector to return one.',
            $connection->key(),
        ));
    }

    public static function notAdvancing(Connection $connection, int $page): self
    {
        return new self(sprintf(
            'Connector [%s] was asked for the same page twice at page %d. '
            .'The provider repeated a cursor or the paginator cannot advance; '
            .'continuing would loop until the page limit.',
            $connection->key(),
            $page,
        ));
    }

    /**
     * A next-page link pointing somewhere else is refused rather than
     * followed: the request carries an Authorization header, and
     * sending it to a host the connection never named would hand the
     * credential to whoever controls that response.
     */
    public static function foreignHost(Connection $connection, string $expected, string $found): self
    {
        return new self(sprintf(
            'Connector [%s] received a next-page link for host [%s], but this connection talks to [%s]. '
            .'Refusing to follow it: the request carries credentials.',
            $connection->key(),
            $found,
            $expected,
        ));
    }
}
