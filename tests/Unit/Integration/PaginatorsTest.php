<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Pagination\CursorPaginator;
use Laraflow\Integration\Pagination\LinkHeaderPaginator;
use Laraflow\Integration\Pagination\PagePaginator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaginatorsTest extends TestCase
{
    // ------------------------------------------------------------------
    // Link header
    // ------------------------------------------------------------------

    public function test_the_link_header_next_relation_becomes_the_next_request(): void
    {
        $paginator = new LinkHeaderPaginator;
        $request = new ConnectorRequest('GET', '/repos', ['query' => ['per_page' => 2], 'headers' => ['X' => 'y']]);
        $response = new ConnectorResponse(200, [
            'Link' => '<https://api.github.com/repos?page=2&per_page=2>; rel="next", <https://api.github.com/repos?page=9>; rel="last"',
            'Content-Type' => 'application/json',
        ], '[{"id":1}]');

        $next = $paginator->next($request, $response);

        $this->assertNotNull($next);
        $this->assertSame('https://api.github.com/repos?page=2&per_page=2', $next->uri);
        $this->assertSame('GET', $next->method);
        $this->assertArrayNotHasKey('query', $next->options, 'The link carries the query; sending ours too would fight it.');
        $this->assertSame(['X' => 'y'], $next->options['headers']);
    }

    public function test_pagination_ends_when_there_is_no_next_relation(): void
    {
        $paginator = new LinkHeaderPaginator;
        $request = new ConnectorRequest('GET', '/repos');

        foreach ([
            'only a last relation' => '<https://api.github.com/repos?page=9>; rel="last"',
            'empty header' => '',
            'nonsense' => 'not a link header',
        ] as $case => $header) {
            $this->assertNull($paginator->next($request, new ConnectorResponse(200, ['Link' => $header])), $case);
        }

        $this->assertNull($paginator->next($request, new ConnectorResponse(200)), 'no header at all');
    }

    public function test_the_link_relation_is_configurable_and_matched_loosely(): void
    {
        $response = new ConnectorResponse(200, ['Link' => '<https://host/a>; rel=next, <https://host/b>; rel="PREV"']);

        $this->assertSame('https://host/a', (new LinkHeaderPaginator)->next(new ConnectorRequest('GET', '/'), $response)?->uri);
        $this->assertSame('https://host/b', (new LinkHeaderPaginator('prev'))->next(new ConnectorRequest('GET', '/'), $response)?->uri);
    }

    public function test_link_header_items_are_the_whole_body_or_a_path(): void
    {
        $array = ConnectorResponse::fake(200, [['id' => 1], ['id' => 2]]);

        $this->assertSame([['id' => 1], ['id' => 2]], (new LinkHeaderPaginator)->items($array));
        $this->assertSame([], (new LinkHeaderPaginator)->items(ConnectorResponse::fake(200, 'not an array')));
        $this->assertSame(
            [['id' => 7]],
            (new LinkHeaderPaginator('next', 'data.items'))->items(ConnectorResponse::fake(200, ['data' => ['items' => [['id' => 7]]]])),
        );
    }

    // ------------------------------------------------------------------
    // Cursor
    // ------------------------------------------------------------------

    public function test_a_cursor_is_sent_back_as_a_query_parameter_beside_the_original_query(): void
    {
        $paginator = new CursorPaginator;
        $request = new ConnectorRequest('GET', '/users.list', ['query' => ['limit' => 200]]);
        $response = ConnectorResponse::fake(200, ['members' => [], 'response_metadata' => ['next_cursor' => 'dXNlcjpVMEc5V0ZYTlo=']]);

        $next = $paginator->next($request, $response);

        $this->assertNotNull($next);
        $this->assertSame('/users.list', $next->uri);
        $this->assertSame(['limit' => 200, 'cursor' => 'dXNlcjpVMEc5V0ZYTlo='], $next->options['query']);
    }

    #[DataProvider('exhaustedCursors')]
    public function test_a_blank_or_absent_cursor_ends_pagination(mixed $cursor): void
    {
        $response = ConnectorResponse::fake(200, ['response_metadata' => ['next_cursor' => $cursor]]);

        $this->assertNull((new CursorPaginator)->next(new ConnectorRequest('GET', '/'), $response));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function exhaustedCursors(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['   '],
            'null' => [null],
            'an array' => [['nope']],
        ];
    }

    public function test_the_cursor_path_parameter_and_items_path_are_configurable(): void
    {
        $paginator = new CursorPaginator('meta.after', 'starting_after', 'data');
        $response = ConnectorResponse::fake(200, ['data' => [['id' => 'a']], 'meta' => ['after' => 'a']]);

        $this->assertSame(['starting_after' => 'a'], $paginator->next(new ConnectorRequest('GET', '/'), $response)?->options['query']);
        $this->assertSame([['id' => 'a']], $paginator->items($response));
    }

    public function test_a_numeric_cursor_is_accepted(): void
    {
        $response = ConnectorResponse::fake(200, ['response_metadata' => ['next_cursor' => 42]]);

        $this->assertSame(['cursor' => '42'], (new CursorPaginator)->next(new ConnectorRequest('GET', '/'), $response)?->options['query']);
    }

    // ------------------------------------------------------------------
    // Page number
    // ------------------------------------------------------------------

    public function test_the_page_number_increments_until_a_page_is_empty(): void
    {
        $paginator = new PagePaginator;
        $full = ConnectorResponse::fake(200, [['id' => 1]]);

        $first = $paginator->next(new ConnectorRequest('GET', '/contacts'), $full);
        $this->assertSame(['page' => 2], $first?->options['query']);

        $second = $paginator->next($first, $full);
        $this->assertSame(['page' => 3], $second?->options['query']);

        $this->assertNull($paginator->next($second, ConnectorResponse::fake(200, [])), 'An empty page is the end.');
    }

    public function test_the_first_page_number_and_parameter_are_configurable(): void
    {
        $paginator = new PagePaginator('offset', 0, 'results');
        $response = ConnectorResponse::fake(200, ['results' => [['id' => 1]]]);

        $this->assertSame(['offset' => 1], $paginator->next(new ConnectorRequest('GET', '/'), $response)?->options['query']);
        $this->assertSame([['id' => 1]], $paginator->items($response));
    }

    public function test_an_existing_page_parameter_is_continued_from(): void
    {
        $request = new ConnectorRequest('GET', '/', ['query' => ['page' => 7, 'q' => 'x']]);

        $this->assertSame(
            ['page' => 8, 'q' => 'x'],
            (new PagePaginator)->next($request, ConnectorResponse::fake(200, [['id' => 1]]))?->options['query'],
        );
    }
}
