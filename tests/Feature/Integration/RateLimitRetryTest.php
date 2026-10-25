<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\ThrottledException;
use Laraflow\Exceptions\SuggestsRetryDelay;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\ConnectorStep;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\ThrowingStep;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The durable half of rate limiting: a provider's Retry-After, or
 * Laraflow's own throttle, decides when the step's retry is due.
 */
class RateLimitRetryTest extends TestCase
{
    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-10-25 12:00:00');

        config(['laraflow.integrations.crm' => [
            'connector' => FakeHttpConnector::class,
            'connections' => ['default' => ['credentials' => ['token' => 't']]],
        ]]);
        $this->app->register(\Laraflow\Core\LaraflowServiceProvider::class, force: true);

        PolicyWorkflow::$steps = [ConnectorStep::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 10, jitter: 0.0);
        ConnectorStep::$connector = 'crm';
        ConnectorStep::$connection = null;
        ConnectorStep::$runs = 0;
        ThrowingStep::$exception = null;
    }

    public function test_a_providers_retry_after_sets_the_durable_retry_schedule(): void
    {
        Event::fake([WorkflowStepRetryScheduled::class]);
        Http::fake(['crm.example/*' => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '120'])]);

        $execution = $this->runner()->run(new PolicyWorkflow);

        $this->assertTrue($execution->isRetrying());
        $this->assertSame('2026-10-25 12:02:00', $execution->nextRetryAt?->format('Y-m-d H:i:s'), 'The provider said 120 s; the policy would have said 10.');
        $this->assertSame(RateLimitedException::class, $execution->errorClass);

        Event::assertDispatched(WorkflowStepRetryScheduled::class, fn (WorkflowStepRetryScheduled $event): bool => $event->delaySeconds === 120);
    }

    public function test_a_shorter_suggestion_never_undercuts_the_policys_backoff(): void
    {
        Http::fake(['crm.example/*' => Http::response([], 429, ['Retry-After' => '2'])]);

        $execution = $this->runner()->run(new PolicyWorkflow);

        $this->assertSame('2026-10-25 12:00:10', $execution->nextRetryAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_suggestion_is_honoured_only_up_to_the_configured_cap(): void
    {
        Http::fake(['crm.example/*' => Http::response([], 429, ['Retry-After' => '86400'])]);

        $execution = $this->runner(new RunnerOptions(maxSuggestedRetryDelaySeconds: 600))->run(new PolicyWorkflow);

        $this->assertSame('2026-10-25 12:10:00', $execution->nextRetryAt?->format('Y-m-d H:i:s'));

        // And the shipped cap is an hour.
        $this->assertSame(3600, RunnerOptions::fromConfig($this->app->make('config'))->maxSuggestedRetryDelaySeconds);
    }

    public function test_an_http_date_retry_after_is_measured_against_the_runners_clock(): void
    {
        Http::fake(['crm.example/*' => Http::response([], 429, ['Retry-After' => 'Sun, 25 Oct 2026 12:05:00 GMT'])]);

        $execution = $this->runner()->run(new PolicyWorkflow);

        $this->assertSame('2026-10-25 12:05:00', $execution->nextRetryAt?->format('Y-m-d H:i:s'));
    }

    public function test_any_exception_may_suggest_a_delay(): void
    {
        PolicyWorkflow::$steps = [ThrowingStep::class];
        ThrowingStep::$exception = new class('come back later') extends RuntimeException implements SuggestsRetryDelay
        {
            public function suggestedRetryDelaySeconds(DateTimeInterface $now): ?int
            {
                return 45;
            }
        };

        $execution = $this->runner()->run(new PolicyWorkflow);

        $this->assertSame('2026-10-25 12:00:45', $execution->nextRetryAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_null_suggestion_falls_back_to_the_policy(): void
    {
        Http::fake(['crm.example/*' => Http::response([], 429)]);

        $execution = $this->runner()->run(new PolicyWorkflow);

        $this->assertSame('2026-10-25 12:00:10', $execution->nextRetryAt?->format('Y-m-d H:i:s'));
    }

    public function test_laraflows_own_throttle_becomes_a_durable_retry_too(): void
    {
        config(['laraflow.integrations.crm.connections.default.rate_limit' => ['max' => 1, 'per' => 300]]);
        $this->app->make(\Laraflow\Core\LaraflowManager::class)->forget('crm');

        Http::fake(['crm.example/*' => Http::response(['ok' => true])]);

        // The first execution spends the budget; the second is refused
        // before reaching the wire and comes back when a slot is due.
        $first = $this->runner()->run(new PolicyWorkflow);
        $second = $this->runner()->run(new PolicyWorkflow);

        $this->assertTrue($first->isCompleted());
        $this->assertTrue($second->isRetrying());
        $this->assertSame(ThrottledException::class, $second->errorClass);
        $this->assertNotNull($second->nextRetryAt);
        $this->assertGreaterThan(10, $second->nextRetryAt->getTimestamp() - $this->clock->now()->getTimestamp(), 'Longer than the policy backoff: the throttle said so.');
        $this->assertLessThanOrEqual(300, $second->nextRetryAt->getTimestamp() - $this->clock->now()->getTimestamp());

        Http::assertSentCount(1);
    }

    protected function runner(?RunnerOptions $options = null): WorkflowRunner
    {
        $logger = new ArrayLogger;

        return new WorkflowRunner(
            repository: new EloquentExecutionRepository($this->clock),
            container: $this->app,
            logger: $logger,
            options: $options,
            events: new EventEmitter($this->app->make(Dispatcher::class), $logger),
            clock: $this->clock,
        );
    }
}
