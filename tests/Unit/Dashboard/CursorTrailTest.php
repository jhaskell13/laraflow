<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Dashboard;

use Illuminate\Http\Request;
use Laraflow\Dashboard\Support\CursorTrail;
use PHPUnit\Framework\TestCase;

/**
 * docs/dashboard.md, "Pagination": previous replays a cursor the
 * repository already issued; it never invents an offset.
 */
class CursorTrailTest extends TestCase
{
    public function test_the_first_page_has_no_cursor_and_nowhere_to_go_back_to(): void
    {
        $trail = CursorTrail::from(Request::create('/'));

        $this->assertTrue($trail->isFirstPage());
        $this->assertNull($trail->previous());
        $this->assertSame(['cursor' => 'c1'], $trail->next('c1'));
    }

    public function test_going_forward_records_where_you_came_from(): void
    {
        $second = CursorTrail::from(Request::create('/', 'GET', ['cursor' => 'c1']));

        $this->assertFalse($second->isFirstPage());
        $this->assertSame([], $second->previous(), 'The page before the second is the first: no cursor.');
        $this->assertSame(['cursor' => 'c2', 'trail' => ['c1']], $second->next('c2'));

        $third = CursorTrail::from(Request::create('/', 'GET', ['cursor' => 'c2', 'trail' => ['c1']]));

        $this->assertSame(['cursor' => 'c1'], $third->previous());
        $this->assertSame(['cursor' => 'c3', 'trail' => ['c1', 'c2']], $third->next('c3'));

        $fourth = CursorTrail::from(Request::create('/', 'GET', ['cursor' => 'c3', 'trail' => ['c1', 'c2']]));

        $this->assertSame(['cursor' => 'c2', 'trail' => ['c1']], $fourth->previous());
    }

    public function test_a_tampered_trail_is_read_as_strings_only(): void
    {
        $trail = CursorTrail::from(Request::create('/', 'GET', [
            'cursor' => 'c2',
            'trail' => ['c1', '', ['nested'], 7],
        ]));

        $this->assertSame(['cursor' => 'c1'], $trail->previous());
    }
}
