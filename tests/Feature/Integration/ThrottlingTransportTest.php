<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Laraflow\Exceptions\Connector\ThrottledException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\RateLimitPolicy;
use Laraflow\Integration\Http\ThrottlingTransport;
use Laraflow\Tests\Support\RecordingSleeper;
use Laraflow\Tests\Support\ScriptedTransport;
use Laraflow\Tests\TestCase;

class ThrottlingTransportTest extends TestCase
{
    protected RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeper = new RecordingSleeper;
    }

    public function test_requests_within_the_budget_pass_and_the_next_is_refused_with_the_wait(): void
    {
        $inner = new ScriptedTransport(array_fill(0, 3, ConnectorResponse::fake()));
        $transport = $this->throttling($inner, new RateLimitPolicy(maxRequests: 2, perSeconds: 60), 'slack', 'acme');

        $transport->send(new ConnectorRequest('GET', '/a'), new HttpOptions);
        $transport->send(new ConnectorRequest('GET', '/b'), new HttpOptions);

        try {
            $transport->send(new ConnectorRequest('GET', '/c'), new HttpOptions);
            $this->fail('Expected ThrottledException.');
        } catch (ThrottledException $exception) {
            $this->assertCount(2, $inner->sent, 'The refused request never reached the wire.');
            $this->assertGreaterThan(0, $exception->availableInSeconds);
            $this->assertLessThanOrEqual(60, $exception->availableInSeconds);
            $this->assertSame($exception->availableInSeconds, $exception->suggestedRetryDelaySeconds(new DateTimeImmutable));
            $this->assertStringContainsString('[slack:acme]', $exception->getMessage());
            $this->assertStringContainsString('2 per 60 seconds', $exception->getMessage());
        }

        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_a_wait_within_max_wait_is_served_in_process(): void
    {
        $inner = new ScriptedTransport(array_fill(0, 2, ConnectorResponse::fake()));
        $transport = $this->throttling($inner, new RateLimitPolicy(maxRequests: 1, perSeconds: 2, maxWaitSeconds: 5), 'slack', 'acme');

        $transport->send(new ConnectorRequest('GET', '/a'), new HttpOptions);
        $transport->send(new ConnectorRequest('GET', '/b'), new HttpOptions);

        $this->assertCount(2, $inner->sent);
        $this->assertCount(1, $this->sleeper->sleeps);
        $this->assertGreaterThan(0, $this->sleeper->sleeps[0]);
        $this->assertLessThanOrEqual(2000, $this->sleeper->sleeps[0]);
    }

    public function test_connections_have_separate_budgets(): void
    {
        $policy = new RateLimitPolicy(maxRequests: 1, perSeconds: 60);
        $acme = $this->throttling(new ScriptedTransport([ConnectorResponse::fake()]), $policy, 'slack', 'acme');
        $globex = $this->throttling(new ScriptedTransport([ConnectorResponse::fake()]), $policy, 'slack', 'globex');

        $acme->send(new ConnectorRequest('GET', '/a'), new HttpOptions);
        $globex->send(new ConnectorRequest('GET', '/a'), new HttpOptions);

        $this->addToAssertionCount(1);
    }

    protected function throttling(ScriptedTransport $inner, RateLimitPolicy $policy, string $connector, string $connection): ThrottlingTransport
    {
        return new ThrottlingTransport(
            $inner,
            $policy,
            new Connection($connector, $connection),
            $this->app->make(RateLimiter::class),
            $this->sleeper,
        );
    }
}
