<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Integration\ConnectorRequest;
use PHPUnit\Framework\TestCase;

class ConnectorRequestTest extends TestCase
{
    public function test_idempotency_is_derived_from_the_method_unless_stated(): void
    {
        foreach (['GET', 'get', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'] as $method) {
            $this->assertTrue((new ConnectorRequest($method, '/'))->isIdempotent(), $method);
        }

        foreach (['POST', 'PATCH'] as $method) {
            $this->assertFalse((new ConnectorRequest($method, '/'))->isIdempotent(), $method);
        }

        $this->assertTrue((new ConnectorRequest('POST', '/'))->asIdempotent()->isIdempotent());
        $this->assertFalse((new ConnectorRequest('GET', '/'))->asIdempotent(false)->isIdempotent());
    }

    public function test_withers_keep_the_idempotency_decision(): void
    {
        $request = (new ConnectorRequest('POST', '/', ['json' => ['a' => 1]]))->asIdempotent();

        $this->assertTrue($request->withOptions(['json' => ['a' => 2]])->isIdempotent());
        $this->assertTrue($request->withHeaders(['X' => 'y'])->isIdempotent());
        $this->assertSame(['json' => ['a' => 1], 'headers' => ['X' => 'y']], $request->withHeaders(['X' => 'y'])->options);
    }
}
