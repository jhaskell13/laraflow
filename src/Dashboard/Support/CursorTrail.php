<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Support;

use Illuminate\Http\Request;

/**
 * @internal How "previous page" works over a forward-only cursor.
 *
 * The repository paginates by keyset and issues cursors in one
 * direction (docs/dashboard.md, "Pagination"). Stepping back therefore
 * replays a cursor the repository already issued rather than inventing
 * an offset: every page link carries the cursors visited to reach it,
 * so the previous page is the last of them and the first page is none
 * of them. Nothing here is interpreted — the cursors stay opaque.
 */
final readonly class CursorTrail
{
    /**
     * @param  string|null  $current  The cursor this page was fetched with; null on the first page.
     * @param  list<string>  $visited  The cursors of the pages before this one, oldest first, not including the first page.
     */
    private function __construct(
        public ?string $current,
        public array $visited,
    ) {}

    public static function from(Request $request): self
    {
        $cursor = $request->query('cursor');
        $trail = $request->query('trail');

        $visited = [];

        foreach (is_array($trail) ? $trail : [] as $item) {
            if (is_string($item) && $item !== '') {
                $visited[] = $item;
            }
        }

        return new self(
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            $visited,
        );
    }

    public function isFirstPage(): bool
    {
        return $this->current === null;
    }

    /**
     * The query parameters that fetch the page after this one.
     *
     * @return array<string, mixed>
     */
    public function next(string $nextCursor): array
    {
        $visited = $this->visited;

        if ($this->current !== null) {
            $visited[] = $this->current;
        }

        return array_filter(['cursor' => $nextCursor, 'trail' => $visited], fn (mixed $value): bool => $value !== []);
    }

    /**
     * The query parameters that fetch the page before this one — the
     * last cursor visited, with a shorter trail — or null on the first
     * page, where there is none.
     *
     * @return array<string, mixed>|null
     */
    public function previous(): ?array
    {
        if ($this->current === null) {
            return null;
        }

        if ($this->visited === []) {
            // The page before this one is the first: no cursor at all.
            return [];
        }

        $visited = $this->visited;
        $cursor = array_pop($visited);

        return array_filter(['cursor' => $cursor, 'trail' => $visited], fn (mixed $value): bool => $value !== []);
    }
}
