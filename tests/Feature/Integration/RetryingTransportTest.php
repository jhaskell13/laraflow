<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\RetryingTransport;
use Laraflow\Integration\Http\TransportPolicy;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\RecordingSleeper;
use Laraflow\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RetryingTransportTest extends TestCase
{
    protected RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeper = new RecordingSleeper;
    }

    public function test_a_retried_status_is_repeated_after_the_computed_wait_and_the_response_counts_attempts(): void
    {
        $inner = new ScriptedTransport([new ConnectorResponse(503), new ConnectorResponse(502), ConnectorResponse::fake(200, ['ok' => true])]);

        $response = $this->retrying($inner, new TransportPolicy(maxAttempts: 3, baseDelayMs: 100, jitter: 0.0))
            ->send(new ConnectorRequest('GET', '/x'), new HttpOptions);

        $this->assertSame(200, $response->status());
        $this->assertSame(3, $response->attempts());
        $this->assertCount(3, $inner->sent);
        $this->assertSame([100, 200], $this->sleeper->sleeps);
    }

    public function test_the_last_failed_response_is_returned_when_the_budget_is_spent(): void
    {
        $inner = new ScriptedTransport([new ConnectorResponse(503), new ConnectorResponse(503)]);

        $response = $this->retrying($inner, new TransportPolicy(maxAttempts: 2, baseDelayMs: 10, jitter: 0.0))
            ->send(new ConnectorRequest('GET', '/x'), new HttpOptions);

        $this->assertSame(503, $response->status());
        $this->assertSame(2, $response->attempts());
        $this->assertSame([10], $this->sleeper->sleeps);
    }

    public function test_a_non_retried_status_is_returned_at_once(): void
    {
        $inner = new ScriptedTransport([new ConnectorResponse(404)]);

        $response = $this->retrying($inner, new TransportPolicy(maxAttempts: 3))->send(new ConnectorRequest('GET', '/x'), new HttpOptions);

        $this->assertSame(404, $response->status());
        $this->assertSame(1, $response->attempts());
        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_a_transport_failure_is_retried_for_an_idempotent_request_and_rethrown_for_another(): void
    {
        $failure = TransportException::connecting('api.example', new RuntimeException('refused'));

        $inner = new ScriptedTransport([$failure, ConnectorResponse::fake()]);
        $response = $this->retrying($inner, new TransportPolicy(maxAttempts: 2, baseDelayMs: 10, jitter: 0.0))
            ->send(new ConnectorRequest('GET', '/x'), new HttpOptions);

        $this->assertSame(2, $response->attempts());
        $this->assertSame([10], $this->sleeper->sleeps);

        $inner = new ScriptedTransport([$failure, ConnectorResponse::fake()]);

        try {
            $this->retrying($inner, new TransportPolicy(maxAttempts: 2))->send(new ConnectorRequest('POST', '/x'), new HttpOptions);
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertCount(1, $inner->sent);
        }
    }

    public function test_the_budget_exhausted_on_failures_rethrows_the_last(): void
    {
        $first = TransportException::connecting('api.example', new RuntimeException('1'));
        $second = TransportException::connecting('api.example', new RuntimeException('2'));

        $inner = new ScriptedTransport([$first, $second]);

        try {
            $this->retrying($inner, new TransportPolicy(maxAttempts: 2, baseDelayMs: 10, jitter: 0.0))->send(new ConnectorRequest('GET', '/x'), new HttpOptions);
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertSame($second, $exception);
        }
    }

    public function test_a_short_retry_after_is_honoured_exactly_and_a_long_one_is_returned(): void
    {
        $inner = new ScriptedTransport([new ConnectorResponse(429, ['Retry-After' => '2']), ConnectorResponse::fake()]);

        $response = $this->retrying($inner, new TransportPolicy(maxAttempts: 3, maxRetryAfterSeconds: 5))
            ->send(new ConnectorRequest('POST', '/x'), new HttpOptions);

        $this->assertSame(200, $response->status(), 'A 429 is safe to repeat even for a POST.');
        $this->assertSame([2000], $this->sleeper->sleeps);

        $this->sleeper = new RecordingSleeper;
        $inner = new ScriptedTransport([new ConnectorResponse(429, ['Retry-After' => '600'])]);

        $response = $this->retrying($inner, new TransportPolicy(maxAttempts: 3, maxRetryAfterSeconds: 5))
            ->send(new ConnectorRequest('GET', '/x'), new HttpOptions);

        $this->assertSame(429, $response->status(), 'Left for the step to retry durably.');
        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_an_http_date_retry_after_is_measured_against_the_clock(): void
    {
        $inner = new ScriptedTransport([new ConnectorResponse(429, ['Retry-After' => 'Sun, 25 Oct 2026 12:00:03 GMT']), ConnectorResponse::fake()]);

        $this->retrying($inner, new TransportPolicy(maxAttempts: 2, maxRetryAfterSeconds: 5), new FixedClock('2026-10-25 12:00:00 GMT'))
            ->send(new ConnectorRequest('GET', '/x'), new HttpOptions);

        $this->assertSame([3000], $this->sleeper->sleeps);
    }

    protected function retrying(ScriptedTransport $inner, TransportPolicy $policy, ?FixedClock $clock = null): RetryingTransport
    {
        return new RetryingTransport($inner, $policy, $this->sleeper, $clock ?? new FixedClock('2026-10-25 12:00:00'));
    }
}
