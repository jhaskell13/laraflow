<?php

declare(strict_types=1);

namespace Laraflow\Integration\Pagination;

use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * How one provider says "there is more, and here is how to ask for it".
 *
 * Providers disagree: GitHub sends a `Link` header, Slack a cursor in
 * the body, plenty of others a page number. A connector supplies the
 * paginator that knows its provider; HttpConnector::pages() drives it.
 */
interface Paginator
{
    /**
     * The request that fetches the page after this response, or null
     * when this was the last one.
     */
    public function next(ConnectorRequest $request, ConnectorResponse $response): ?ConnectorRequest;

    /**
     * The items carried by one page, in order. An empty array is a
     * valid page; it is not a signal to stop (that is next()'s job).
     *
     * @return array<int, mixed>
     */
    public function items(ConnectorResponse $response): array;
}
