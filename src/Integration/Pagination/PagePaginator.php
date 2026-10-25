<?php

declare(strict_types=1);

namespace Laraflow\Integration\Pagination;

use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * Page-number pagination: ask for page 2, then 3, until a page comes
 * back empty.
 *
 * The emptiness rule is the only one available without a provider
 * telling us the total, and it costs one extra request at the end. A
 * provider that reports a last page is better served by a subclass
 * overriding next().
 */
class PagePaginator implements Paginator
{
    /**
     * @param  string  $parameter  Query parameter carrying the page number.
     * @param  int  $firstPage  The number the provider calls the first page.
     * @param  string|null  $itemsPath  Dot path to the items; null means the whole body.
     */
    public function __construct(
        protected string $parameter = 'page',
        protected int $firstPage = 1,
        protected ?string $itemsPath = null,
    ) {}

    public function next(ConnectorRequest $request, ConnectorResponse $response): ?ConnectorRequest
    {
        if ($this->items($response) === []) {
            return null;
        }

        $options = $request->options;
        $query = $options['query'] ?? [];
        $query = is_array($query) ? $query : [];

        $current = isset($query[$this->parameter]) && is_numeric($query[$this->parameter])
            ? (int) $query[$this->parameter]
            : $this->firstPage;

        $query[$this->parameter] = $current + 1;
        $options['query'] = $query;

        return $request->withOptions($options);
    }

    /**
     * @return array<int, mixed>
     */
    public function items(ConnectorResponse $response): array
    {
        $items = $this->itemsPath === null ? $response->json() : $response->json($this->itemsPath);

        return is_array($items) ? array_values($items) : [];
    }
}
