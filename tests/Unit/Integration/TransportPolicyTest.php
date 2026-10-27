<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use InvalidArgumentException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\TransportPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransportPolicyTest extends TestCase
{
    public function test_it_ships_disabled(): void
    {
        $policy = new TransportPolicy;

        $this->assertFalse($policy->enabled());
        $this->assertSame(1, $policy->maxAttempts);
        $this->assertFalse($policy->shouldRetryResponse(new ConnectorResponse(503), $this->get(), 1, null));
        $this->assertFalse($policy->shouldRetryFailure($this->get(), 1));
    }

    public function test_retried_statuses_are_retried_until_the_budget_is_spent(): void
    {
        $policy = new TransportPolicy(maxAttempts: 3);

        $this->assertTrue($policy->shouldRetryResponse(new ConnectorResponse(503), $this->get(), 1, null));
        $this->assertTrue($policy->shouldRetryResponse(new ConnectorResponse(503), $this->get(), 2, null));
        $this->assertFalse($policy->shouldRetryResponse(new ConnectorResponse(503), $this->get(), 3, null), 'Attempt 3 of 3 is the last.');
        $this->assertFalse($policy->shouldRetryResponse(new ConnectorResponse(404), $this->get(), 1, null), 'Not a retried status.');
        $this->assertFalse($policy->shouldRetryResponse(new ConnectorResponse(200), $this->get(), 1, null));
    }

    public function test_a_non_idempotent_request_is_retried_only_on_429_or_when_marked_safe(): void
    {
        $policy = new TransportPolicy(maxAttempts: 3);
        $post = new ConnectorRequest('POST', '/charges');

        $this->assertFalse($policy->shouldRetryResponse(new ConnectorResponse(503), $post, 1, null), 'A 503 may have been processed.');
        $this->assertTrue($policy->shouldRetryResponse(new ConnectorResponse(429), $post, 1, null), 'A 429 was never processed.');
        $this->assertTrue($policy->shouldRetryResponse(new ConnectorResponse(503), $post->asIdempotent(), 1, null));
        $this->assertFalse($policy->shouldRetryFailure($post, 1));
        $this->assertTrue($policy->shouldRetryFailure($post->asIdempotent(), 1));
        $this->assertTrue($policy->shouldRetryFailure($this->get(), 1));
        $this->assertFalse((new TransportPolicy(maxAttempts: 3, retryTransportFailures: false))->shouldRetryFailure($this->get(), 1));
    }

    public function test_a_long_retry_after_is_left_to_the_durable_path(): void
    {
        $policy = new TransportPolicy(maxAttempts: 3, maxRetryAfterSeconds: 5);

        $this->assertTrue($policy->shouldRetryResponse(new ConnectorResponse(429), $this->get(), 1, 5));
        $this->assertFalse($policy->shouldRetryResponse(new ConnectorResponse(429), $this->get(), 1, 6));
        $this->assertSame(5000, $policy->delayMs(1, 5));
    }

    public function test_backoff_grows_is_capped_and_is_deterministic(): void
    {
        $policy = new TransportPolicy(maxAttempts: 5, baseDelayMs: 100, multiplier: 2.0, maxDelayMs: 500, jitter: 0.0);

        $this->assertSame([100, 200, 400, 500], array_map(fn (int $n): int => $policy->delayMs($n, null), [1, 2, 3, 4]));

        $jittered = new TransportPolicy(maxAttempts: 5, baseDelayMs: 1000, jitter: 0.5, maxDelayMs: 10000);

        $this->assertSame($jittered->delayMs(1, null, 'GET /a'), $jittered->delayMs(1, null, 'GET /a'));
        $this->assertGreaterThanOrEqual(500, $jittered->delayMs(1, null, 'GET /a'));
        $this->assertLessThanOrEqual(1500, $jittered->delayMs(1, null, 'GET /a'));
    }

    public function test_from_array_merges_over_a_base_treating_blank_as_keep(): void
    {
        $base = new TransportPolicy(maxAttempts: 3, baseDelayMs: 50);

        $policy = TransportPolicy::fromArray(['max_attempts' => '', 'statuses' => [502, 503], 'max_retry_after' => 2], $base);

        $this->assertSame(3, $policy->maxAttempts);
        $this->assertSame(50, $policy->baseDelayMs);
        $this->assertSame([502, 503], $policy->statuses);
        $this->assertSame(2, $policy->maxRetryAfterSeconds);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('invalid')]
    public function test_invalid_settings_are_rejected(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TransportPolicy(...$arguments);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalid(): array
    {
        return [
            'zero attempts' => [['maxAttempts' => 0]],
            'negative delay' => [['baseDelayMs' => -1]],
            'shrinking multiplier' => [['multiplier' => 0.5]],
            'jitter above one' => [['jitter' => 1.5]],
            'negative retry-after cap' => [['maxRetryAfterSeconds' => -1]],
            'non-error status' => [['statuses' => [200]]],
        ];
    }

    protected function get(): ConnectorRequest
    {
        return new ConnectorRequest('GET', '/x');
    }
}
