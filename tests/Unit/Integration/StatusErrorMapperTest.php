<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use DateTimeImmutable;
use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\ClientErrorException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\ServerErrorException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\StatusErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusErrorMapperTest extends TestCase
{
    /**
     * @param  class-string<object>|null  $expected
     */
    #[DataProvider('classifications')]
    public function test_it_classifies_by_status(int $status, ?string $expected): void
    {
        $error = $this->map(new ConnectorResponse($status));

        if ($expected === null) {
            $this->assertNull($error);
        } else {
            $this->assertInstanceOf($expected, $error);
        }
    }

    /**
     * @return array<array-key, array{int, class-string<object>|null}>
     */
    public static function classifications(): array
    {
        return [
            '200' => [200, null],
            '204' => [204, null],
            '302' => [302, null],
            '400' => [400, ClientErrorException::class],
            '401' => [401, AuthenticationFailedException::class],
            '403' => [403, AuthenticationFailedException::class],
            '404' => [404, NotFoundException::class],
            '409' => [409, ClientErrorException::class],
            '422' => [422, ClientErrorException::class],
            '429' => [429, RateLimitedException::class],
            '500' => [500, ServerErrorException::class],
            '503' => [503, ServerErrorException::class],
        ];
    }

    public function test_the_message_names_connection_method_path_status_and_the_providers_detail(): void
    {
        $error = $this->map(
            ConnectorResponse::fake(422, ['message' => "Validation Failed:\n  name is required"]),
            new ConnectorRequest('post', '/repos/acme/app/issues?access_token=leak&page=2'),
        );

        $this->assertSame(
            'Connector [github:enterprise] received 422 for POST /repos/acme/app/issues: Validation Failed: name is required',
            $error?->getMessage(),
        );
    }

    public function test_detail_is_taken_from_the_usual_fields_in_order(): void
    {
        $this->assertStringEndsWith(': from error_description', (string) $this->map(ConnectorResponse::fake(400, ['error' => 'code', 'error_description' => 'from error_description']))?->getMessage());
        $this->assertStringEndsWith(': nested', (string) $this->map(ConnectorResponse::fake(400, ['error' => ['message' => 'nested']]))?->getMessage());
        $this->assertStringEndsWith(': plain code', (string) $this->map(ConnectorResponse::fake(400, ['error' => 'plain code']))?->getMessage());
        $this->assertStringEndsWith('received 400 for GET /.', (string) $this->map(ConnectorResponse::fake(400, ['unrelated' => 'x']))?->getMessage());
        $this->assertStringEndsWith('received 500 for GET /.', (string) $this->map(new ConnectorResponse(500, ['Content-Type' => 'text/html'], '<h1>Oops</h1>'))?->getMessage());
    }

    public function test_detail_is_truncated(): void
    {
        $error = $this->map(ConnectorResponse::fake(400, ['message' => str_repeat('x', 500)]));

        $this->assertLessThan(300, mb_strlen((string) $error?->getMessage()));
        $this->assertStringEndsWith('…', (string) $error?->getMessage());
    }

    public function test_declared_but_malformed_json_still_classifies_by_status(): void
    {
        $error = $this->map(new ConnectorResponse(503, ['Content-Type' => 'application/json'], '<html>'));

        $this->assertInstanceOf(ServerErrorException::class, $error);
    }

    public function test_the_response_travels_with_the_exception(): void
    {
        $response = ConnectorResponse::fake(404, ['message' => 'gone']);
        $error = $this->map($response);

        $this->assertSame($response, $error?->response);
        $this->assertSame(404, $error->status());
    }

    public function test_retry_after_in_seconds_and_as_a_date(): void
    {
        $now = new DateTimeImmutable('2026-10-25 12:00:00 GMT');

        $seconds = $this->map(new ConnectorResponse(429, ['Retry-After' => '120']));
        $date = $this->map(new ConnectorResponse(429, ['Retry-After' => 'Sun, 25 Oct 2026 12:05:00 GMT']));
        $past = $this->map(new ConnectorResponse(429, ['Retry-After' => 'Sun, 25 Oct 2026 11:00:00 GMT']));
        $garbage = $this->map(new ConnectorResponse(429, ['Retry-After' => 'soon']));
        $none = $this->map(new ConnectorResponse(429));

        $this->assertInstanceOf(RateLimitedException::class, $seconds);
        $this->assertSame(120, $seconds->retryAfterSeconds($now));
        $this->assertSame('120', $seconds->retryAfter());
        $this->assertInstanceOf(RateLimitedException::class, $date);
        $this->assertSame(300, $date->retryAfterSeconds($now));
        $this->assertInstanceOf(RateLimitedException::class, $past);
        $this->assertSame(0, $past->retryAfterSeconds($now));
        $this->assertInstanceOf(RateLimitedException::class, $garbage);
        $this->assertNull($garbage->retryAfterSeconds($now));
        $this->assertInstanceOf(RateLimitedException::class, $none);
        $this->assertNull($none->retryAfterSeconds($now));
        $this->assertNull($none->retryAfter());
    }

    protected function map(ConnectorResponse $response, ?ConnectorRequest $request = null): mixed
    {
        return (new StatusErrorMapper)->map(
            $request ?? new ConnectorRequest('GET', '/'),
            $response,
            new Connection('github', 'enterprise'),
        );
    }
}
