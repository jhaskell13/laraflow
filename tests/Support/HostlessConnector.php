<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Integration\HttpConnector;
use Laraflow\Integration\Pagination\LinkHeaderPaginator;
use Laraflow\Integration\Pagination\Paginator;

/**
 * A paginating connector that declares no base URL, so every request
 * carries an absolute URI. There is no host to hold a next-page link
 * to, which is what PaginationException::unverifiableHost() refuses.
 */
class HostlessConnector extends HttpConnector
{
    protected function baseUrl(): ?string
    {
        return null;
    }

    protected function paginator(): ?Paginator
    {
        return new LinkHeaderPaginator;
    }
}
