<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use DateTimeImmutable;
use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\ServerErrorException;
use Laraflow\Exceptions\SuggestsRetryDelay;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Github\GithubErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub's 403 means two things: a refused credential, which retrying
 * never fixes, and an exhausted limit, which waiting always does. The
 * classification is the retry decision, so it is pinned per header.
 */
class GithubErrorMapperTest extends TestCase
{
    /**
     * @param  array<string, string>  $headers
     * @param  class-string<object>|null  $expected
     */
    #[DataProvider('classifications')]
    public function test_it_classifies_a_403_by_its_rate_limit_headers(int $status, array $headers, ?string $expected): void
    {
        $error = $this->map(ConnectorResponse::fake($status, ['message' => 'x'], $headers));

        $expected === null ? $this->assertNull($error) : $this->assertInstanceOf($expected, $error);
    }

    /**
     * @return array<string, array{int, array<string, string>, class-string<object>|null}>
     */
    public static function classifications(): array
    {
        return [
            'primary limit exhausted' => [403, ['X-RateLimit-Remaining' => '0'], RateLimitedException::class],
            'secondary limit' => [403, ['X-RateLimit-Remaining' => '4999', 'Retry-After' => '60'], RateLimitedException::class],
            'bare Retry-After' => [403, ['Retry-After' => '30'], RateLimitedException::class],
            'refused credential' => [403, ['X-RateLimit-Remaining' => '4999'], AuthenticationFailedException::class],
            'no headers at all' => [403, [], AuthenticationFailedException::class],
            '401 with remaining 0 stays auth' => [401, ['X-RateLimit-Remaining' => '0'], AuthenticationFailedException::class],
            '404 falls through' => [404, [], NotFoundException::class],
            '429 falls through' => [429, [], RateLimitedException::class],
            '502 falls through' => [502, [], ServerErrorException::class],
            'success with remaining 0' => [200, ['X-RateLimit-Remaining' => '0'], null],
        ];
    }

    public function test_the_secondary_limits_wait_is_offered_to_the_retry_layer(): void
    {
        $error = $this->map(ConnectorResponse::fake(403, ['message' => 'You have exceeded a secondary rate limit'], ['Retry-After' => '60']));

        $this->assertInstanceOf(RateLimitedException::class, $error);
        $this->assertInstanceOf(SuggestsRetryDelay::class, $error);
        $this->assertSame(60, $error->suggestedRetryDelaySeconds(new DateTimeImmutable));
        $this->assertStringEndsWith(': You have exceeded a secondary rate limit', $error->getMessage());
    }

    public function test_header_names_are_case_insensitive(): void
    {
        $this->assertInstanceOf(RateLimitedException::class, $this->map(ConnectorResponse::fake(403, null, ['x-ratelimit-remaining' => '0'])));
    }

    protected function map(ConnectorResponse $response): mixed
    {
        return (new GithubErrorMapper)->map(new ConnectorRequest('GET', '/user'), $response, new Connection('github', 'default'));
    }
}
