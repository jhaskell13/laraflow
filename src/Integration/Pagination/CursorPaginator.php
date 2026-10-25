<?php

declare(strict_types=1);

namespace Laraflow\Integration\Pagination;

use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * Cursor pagination: the response carries an opaque cursor for the next
 * page, which is sent back as a query parameter. Slack's
 * `response_metadata.next_cursor` is the archetype.
 *
 * A blank cursor means the end, which is how most providers of this
 * shape say so.
 */
class CursorPaginator implements Paginator
{
    /**
     * @param  string  $cursorPath  Dot path to the cursor in the response body.
     * @param  string  $parameter  Query parameter the cursor is sent back in.
     * @param  string|null  $itemsPath  Dot path to the items; null means the whole body.
     */
    public function __construct(
        protected string $cursorPath = 'response_metadata.next_cursor',
        protected string $parameter = 'cursor',
        protected ?string $itemsPath = null,
    ) {}

    public function next(ConnectorRequest $request, ConnectorResponse $response): ?ConnectorRequest
    {
        $cursor = $response->json($this->cursorPath);

        if (! is_string($cursor) && ! is_int($cursor)) {
            return null;
        }

        $cursor = (string) $cursor;

        if (trim($cursor) === '') {
            return null;
        }

        $options = $request->options;
        $query = $options['query'] ?? [];
        $query = is_array($query) ? $query : [];
        $query[$this->parameter] = $cursor;
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
