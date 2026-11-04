<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Integration\Http\Headers;
use PHPUnit\Framework\TestCase;

class HeadersTest extends TestCase
{
    public function test_the_upper_layer_wins_by_name_regardless_of_case_and_keeps_its_spelling(): void
    {
        $this->assertSame(
            ['accept' => 'text/plain', 'User-Agent' => 'laraflow'],
            Headers::merge(['Accept' => 'application/json', 'User-Agent' => 'laraflow'], ['accept' => 'text/plain']),
        );
    }

    public function test_an_override_keeps_the_lower_layers_order(): void
    {
        $merged = Headers::merge(['A' => '1', 'B' => '2', 'C' => '3'], ['b' => 'two', 'D' => '4']);

        $this->assertSame(['A', 'b', 'C', 'D'], array_keys($merged));
    }

    public function test_set_collapses_every_spelling_already_present_into_one(): void
    {
        // A hand-built map can already hold two spellings; setting the
        // header must not leave the other one to be sent as well.
        $this->assertSame(
            ['X-Id' => 'new', 'Other' => 'o'],
            Headers::set(['x-id' => 'a', 'Other' => 'o', 'X-ID' => 'b'], 'X-Id', 'new'),
        );
    }

    public function test_merging_with_nothing_changes_nothing(): void
    {
        $this->assertSame(['A' => '1'], Headers::merge(['A' => '1'], []));
        $this->assertSame(['A' => '1'], Headers::merge([], ['A' => '1']));
    }
}
