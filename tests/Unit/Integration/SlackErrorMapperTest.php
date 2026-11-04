<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use DateTimeImmutable;
use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\ClientErrorException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\ServerErrorException;
use Laraflow\Exceptions\SuggestsRetryDelay;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Slack\SlackErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Slack's failures arrive as 200s, so the envelope's `error` code is
 * the only thing between a transient outage (retry) and a revoked
 * token or a typo'd channel (never retry). Pinned code by code.
 */
class SlackErrorMapperTest extends TestCase
{
    /**
     * @param  class-string<object>  $expected
     */
    #[DataProvider('codes')]
    public function test_it_classifies_the_envelopes_error_code(string $code, string $expected): void
    {
        $this->assertInstanceOf($expected, $this->map(ConnectorResponse::fake(200, ['ok' => false, 'error' => $code])));
    }

    /**
     * @return array<string, array{string, class-string<object>}>
     */
    public static function codes(): array
    {
        return [
            'invalid_auth' => ['invalid_auth', AuthenticationFailedException::class],
            'token_revoked' => ['token_revoked', AuthenticationFailedException::class],
            'missing_scope' => ['missing_scope', AuthenticationFailedException::class],
            'channel_not_found' => ['channel_not_found', NotFoundException::class],
            'thread_not_found' => ['thread_not_found', NotFoundException::class],
            'ratelimited' => ['ratelimited', RateLimitedException::class],
            'rate_limited' => ['rate_limited', RateLimitedException::class],
            'internal_error' => ['internal_error', ServerErrorException::class],
            'service_unavailable' => ['service_unavailable', ServerErrorException::class],
            'request_timeout' => ['request_timeout', ServerErrorException::class],
            'invalid_arguments' => ['invalid_arguments', ClientErrorException::class],
            'an unknown code' => ['something_new', ClientErrorException::class],
        ];
    }

    /**
     * @param  class-string<object>|null  $expected
     */
    #[DataProvider('statuses')]
    public function test_a_non_2xx_status_is_classified_by_status(int $status, ?string $expected): void
    {
        // Even with an envelope claiming success: the transport layer spoke.
        $error = $this->map(ConnectorResponse::fake($status, ['ok' => true], ['Retry-After' => '30']));

        $expected === null ? $this->assertNull($error) : $this->assertInstanceOf($expected, $error);
    }

    /**
     * @return array<array-key, array{int, class-string<object>|null}>
     */
    public static function statuses(): array
    {
        return [
            '301' => [301, null],
            '429' => [429, RateLimitedException::class],
            '500' => [500, ServerErrorException::class],
            '503' => [503, ServerErrorException::class],
        ];
    }

    public function test_a_429s_retry_after_is_offered_to_the_retry_layer(): void
    {
        $error = $this->map(new ConnectorResponse(429, ['Retry-After' => '30']));

        $this->assertInstanceOf(SuggestsRetryDelay::class, $error);
        $this->assertSame(30, $error->suggestedRetryDelaySeconds(new DateTimeImmutable));
    }

    public function test_responses_that_are_not_failed_envelopes_are_not_errors(): void
    {
        $this->assertNull($this->map(ConnectorResponse::fake(200, ['ok' => true])));
        $this->assertNull($this->map(ConnectorResponse::fake(200, ['channel' => 'C1'])), 'no ok key');
        $this->assertNull($this->map(ConnectorResponse::fake(200, ['ok' => 'false'])), 'only boolean false fails');
        $this->assertNull($this->map(ConnectorResponse::fake(200, ['ok', false])), 'a list is not an envelope');
        $this->assertNull($this->map(new ConnectorResponse(200, ['Content-Type' => 'text/plain'], 'file bytes')));
        $this->assertNull($this->map(new ConnectorResponse(200, ['Content-Type' => 'application/json'], '{not json')));
    }

    public function test_a_missing_or_non_string_code_is_an_unknown_client_error(): void
    {
        $missing = $this->map(ConnectorResponse::fake(200, ['ok' => false]));
        $nonString = $this->map(ConnectorResponse::fake(200, ['ok' => false, 'error' => ['nested']]));

        $this->assertInstanceOf(ClientErrorException::class, $missing);
        $this->assertStringEndsWith(': unknown_error', $missing->getMessage());
        $this->assertInstanceOf(ClientErrorException::class, $nonString);
        $this->assertStringEndsWith(': unknown_error', $nonString->getMessage());
    }

    public function test_the_detail_carries_the_first_response_metadata_message(): void
    {
        $with = $this->map(ConnectorResponse::fake(200, [
            'ok' => false,
            'error' => 'invalid_arguments',
            'response_metadata' => ['messages' => ['[ERROR] missing required field: channel', 'second']],
        ]));
        $blank = $this->map(ConnectorResponse::fake(200, [
            'ok' => false,
            'error' => 'invalid_arguments',
            'response_metadata' => ['messages' => ['  ']],
        ]));

        $this->assertStringEndsWith(': invalid_arguments ([ERROR] missing required field: channel)', (string) $with?->getMessage());
        $this->assertStringEndsWith(': invalid_arguments', (string) $blank?->getMessage());
    }

    protected function map(ConnectorResponse $response): mixed
    {
        return (new SlackErrorMapper)->map(new ConnectorRequest('POST', '/chat.postMessage'), $response, new Connection('slack', 'default'));
    }
}
