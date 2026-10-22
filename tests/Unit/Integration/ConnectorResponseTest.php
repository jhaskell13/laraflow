<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Integration\ConnectorResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConnectorResponseTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_success_is_the_2xx_range(int $status, bool $expectedSuccess): void
    {
        $response = new ConnectorResponse(status: $status, headers: [], body: null);

        $this->assertSame($expectedSuccess, $response->successful());
        $this->assertSame(! $expectedSuccess, $response->failed());
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function statuses(): array
    {
        return [
            '199 is not success' => [199, false],
            '200 is success' => [200, true],
            '204 is success' => [204, true],
            '299 is success' => [299, true],
            '300 is not success' => [300, false],
            '404 is not success' => [404, false],
            '500 is not success' => [500, false],
        ];
    }

    public function test_it_exposes_status_headers_and_body(): void
    {
        $response = new ConnectorResponse(
            status: 201,
            headers: ['Content-Type' => ['application/json']],
            body: ['id' => 7],
        );

        $this->assertSame(201, $response->status());
        $this->assertSame(['Content-Type' => ['application/json']], $response->headers());
        $this->assertSame(['id' => 7], $response->body());
    }
}
