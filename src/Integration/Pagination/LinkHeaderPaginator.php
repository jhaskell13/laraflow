<?php

declare(strict_types=1);

namespace Laraflow\Integration\Pagination;

use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * RFC 8288 `Link` header pagination: the response names the next page's
 * URL. GitHub's shape.
 *
 * The URL is absolute, which the transport sends as given rather than
 * against the base URL — so HttpConnector checks its host before
 * following it. See PaginationException::foreignHost().
 */
class LinkHeaderPaginator implements Paginator
{
    /**
     * @param  string  $relation  The link relation to follow.
     * @param  string|null  $itemsPath  Dot path to the items; null means the whole body.
     */
    public function __construct(
        protected string $relation = 'next',
        protected ?string $itemsPath = null,
    ) {}

    public function next(ConnectorRequest $request, ConnectorResponse $response): ?ConnectorRequest
    {
        $url = $this->link($response->header('Link'));

        if ($url === null) {
            return null;
        }

        // The query is part of the link; sending the original query
        // alongside it would fight the provider's own paging.
        $options = $request->options;
        unset($options['query']);

        return new ConnectorRequest($request->method, $url, $options, $request->idempotent);
    }

    /**
     * @return array<int, mixed>
     */
    public function items(ConnectorResponse $response): array
    {
        $items = $this->itemsPath === null ? $response->json() : $response->json($this->itemsPath);

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * The URL for this paginator's relation, from a `Link` header:
     * `<https://host/path?page=2>; rel="next", <...>; rel="last"`.
     */
    protected function link(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        foreach (explode(',', $header) as $candidate) {
            if (preg_match('/<\s*([^>]+)\s*>\s*;(.*)$/', trim($candidate), $matches) !== 1) {
                continue;
            }

            if (preg_match('/rel\s*=\s*"?([^";]+)"?/i', $matches[2], $relation) !== 1) {
                continue;
            }

            if (strcasecmp(trim($relation[1]), $this->relation) === 0) {
                return trim($matches[1]);
            }
        }

        return null;
    }
}
