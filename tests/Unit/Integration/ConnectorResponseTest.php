<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Exceptions\Connector\ResponseDecodingException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConnectorResponseTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_success_is_the_2xx_range(int $status, bool $expectedSuccess): void
    {
        $response = new ConnectorResponse($status);

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

    public function test_headers_are_normalised_to_lists_and_read_case_insensitively(): void
    {
        $response = new ConnectorResponse(200, ['Content-Type' => 'application/json', 'X-Multi' => ['a', 'b']]);

        $this->assertSame(['Content-Type' => ['application/json'], 'X-Multi' => ['a', 'b']], $response->headers());
        $this->assertSame('application/json', $response->header('content-type'));
        $this->assertSame('a', $response->header('x-multi'));
        $this->assertNull($response->header('Missing'));
    }

    public function test_a_declared_json_body_is_decoded_by_body_and_json(): void
    {
        $response = new ConnectorResponse(201, ['Content-Type' => 'application/json; charset=utf-8'], '{"id":7,"tags":["a"],"meta":{"page":2}}');

        $this->assertTrue($response->isJson());
        $this->assertSame(['id' => 7, 'tags' => ['a'], 'meta' => ['page' => 2]], $response->body());
        $this->assertSame(7, $response->json('id'));
        $this->assertSame(2, $response->json('meta.page'));
        $this->assertSame('none', $response->json('meta.missing', 'none'));
        $this->assertSame('{"id":7,"tags":["a"],"meta":{"page":2}}', $response->raw());
    }

    public function test_vendor_json_types_count_as_json(): void
    {
        $response = new ConnectorResponse(200, ['Content-Type' => 'application/vnd.github+json'], '{"ok":true}');

        $this->assertTrue($response->isJson());
        $this->assertSame(['ok' => true], $response->body());
    }

    public function test_a_non_json_body_is_returned_as_received_and_an_empty_one_as_null(): void
    {
        $html = new ConnectorResponse(503, ['Content-Type' => 'text/html'], '<html>maintenance</html>');
        $empty = new ConnectorResponse(204);

        $this->assertFalse($html->isJson());
        $this->assertSame('<html>maintenance</html>', $html->body());
        $this->assertNull($empty->body());
        $this->assertNull($empty->json());
    }

    public function test_json_decodes_whatever_the_server_declared(): void
    {
        $response = new ConnectorResponse(200, ['Content-Type' => 'text/plain'], '{"ok":true}');

        $this->assertSame('{"ok":true}', $response->body(), 'body() trusts the declaration.');
        $this->assertSame(['ok' => true], $response->json(), 'json() does not need it.');
    }

    public function test_malformed_json_is_an_error_naming_status_and_type_but_not_the_body(): void
    {
        $response = new ConnectorResponse(502, ['Content-Type' => 'application/json'], '<html>secret gateway page</html>');

        try {
            $response->body();
            $this->fail('Expected ResponseDecodingException.');
        } catch (ResponseDecodingException $exception) {
            $this->assertSame('The response (status 502, content type application/json) is not valid JSON.', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function test_a_json_null_and_an_empty_body_are_both_null_but_the_raw_body_tells_them_apart(): void
    {
        $null = new ConnectorResponse(200, ['Content-Type' => 'application/json'], 'null');
        $empty = new ConnectorResponse(200, ['Content-Type' => 'application/json'], '');

        $this->assertNull($null->body());
        $this->assertNull($empty->body());
        $this->assertSame('null', $null->raw());
        $this->assertSame('', $empty->raw());
    }

    public function test_an_attached_error_makes_even_a_200_a_failure_and_throw_raises_it(): void
    {
        $plain = ConnectorResponse::fake(200, ['ok' => false]);
        $error = NotFoundException::fromResponse(new ConnectorRequest('GET', '/x'), $plain, new Connection('c', 'd'));
        $failed = $plain->withError($error);

        $this->assertTrue($plain->successful());
        $this->assertTrue($failed->failed());
        $this->assertSame($error, $failed->error());
        $this->assertNull($plain->error());

        $this->expectException(NotFoundException::class);
        $failed->throw();
    }

    public function test_throw_on_an_unmapped_error_status_raises_a_generic_failure(): void
    {
        try {
            (new ConnectorResponse(500))->throw();
            $this->fail('Expected RequestFailedException.');
        } catch (RequestFailedException $exception) {
            $this->assertSame('The request failed with status 500.', $exception->getMessage());
            $this->assertSame(500, $exception->status());
        }
    }

    public function test_throw_returns_a_successful_response_for_chaining(): void
    {
        $response = ConnectorResponse::fake(200, ['id' => 1]);

        $this->assertSame(1, $response->throw()->json('id'));
    }

    public function test_fake_builds_a_json_response(): void
    {
        $response = ConnectorResponse::fake(201, ['id' => 1], ['X-Request-Id' => 'r1']);

        $this->assertSame(201, $response->status());
        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertSame('r1', $response->header('X-Request-Id'));
        $this->assertSame(['id' => 1], $response->body());
        $this->assertSame(['status' => 201, 'headers' => ['X-Request-Id' => ['r1'], 'Content-Type' => ['application/json']], 'body' => ['id' => 1]], $response->toArray());
    }
}
