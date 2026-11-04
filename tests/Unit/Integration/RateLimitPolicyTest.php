<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use InvalidArgumentException;
use Laraflow\Integration\Http\RateLimitPolicy;
use Laraflow\Integration\Http\RateLimitScope;
use PHPUnit\Framework\TestCase;

class RateLimitPolicyTest extends TestCase
{
    public function test_nothing_configured_means_no_ceiling(): void
    {
        $this->assertNull(RateLimitPolicy::fromArray(null));
        $this->assertNull(RateLimitPolicy::fromArray([]));
    }

    public function test_from_array_fills_defaults_and_merges_over_a_base(): void
    {
        $policy = RateLimitPolicy::fromArray(['max' => 60]);

        $this->assertNotNull($policy);
        $this->assertSame(60, $policy->maxRequests);
        $this->assertSame(60, $policy->perSeconds);
        $this->assertSame(0, $policy->maxWaitSeconds);

        $overridden = RateLimitPolicy::fromArray(['max_wait' => 2, 'per' => ''], $policy);

        $this->assertNotNull($overridden);
        $this->assertSame(60, $overridden->maxRequests);
        $this->assertSame(60, $overridden->perSeconds, 'Blank keeps the base.');
        $this->assertSame(2, $overridden->maxWaitSeconds);

        $this->assertSame($policy, RateLimitPolicy::fromArray([], $policy));
    }

    public function test_the_scope_defaults_to_the_connection_and_is_inherited_from_the_base(): void
    {
        $fleetWide = RateLimitPolicy::fromArray(['max' => 60, 'scope' => 'tenant']);

        $this->assertNotNull($fleetWide);
        $this->assertSame(RateLimitScope::Tenant, $fleetWide->scope);
        $this->assertSame(RateLimitScope::Connection, RateLimitPolicy::fromArray(['max' => 60])?->scope);

        // A connection that says nothing about scope keeps the fleet's;
        // one that says so goes back to its own account's budget.
        $this->assertSame(RateLimitScope::Tenant, RateLimitPolicy::fromArray(['max' => 10], $fleetWide)?->scope);
        $this->assertSame(RateLimitScope::Connection, RateLimitPolicy::fromArray(['scope' => 'connection'], $fleetWide)?->scope);
        $this->assertSame(RateLimitScope::Tenant, RateLimitPolicy::fromArray(['scope' => ''], $fleetWide)?->scope, 'Blank keeps the base.');
        $this->assertSame(RateLimitScope::Tenant, RateLimitPolicy::fromArray(['max' => 60, 'scope' => ' Tenant '])?->scope, 'Case and whitespace are forgiven.');
    }

    public function test_an_unknown_scope_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_limit.scope must be "connection" or "tenant"');

        RateLimitPolicy::fromArray(['max' => 60, 'scope' => 'workflow']);
    }

    public function test_false_lifts_an_inherited_ceiling(): void
    {
        $fleetWide = RateLimitPolicy::fromArray(['max' => 60]);

        $this->assertNotNull($fleetWide);
        $this->assertNull(
            RateLimitPolicy::fromArray(false, $fleetWide),
            'A connection with its own quota must be able to opt out, not only raise the number.',
        );
        $this->assertNull(RateLimitPolicy::fromArray(false));
    }

    public function test_a_ceiling_without_a_maximum_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RateLimitPolicy::fromArray(['per' => 60]);
    }

    public function test_invalid_values_are_refused(): void
    {
        foreach ([
            fn () => new RateLimitPolicy(0),
            fn () => new RateLimitPolicy(1, 0),
            fn () => new RateLimitPolicy(1, 1, -1),
        ] as $construct) {
            try {
                $construct();
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
