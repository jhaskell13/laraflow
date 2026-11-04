<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\ConnectorObserver;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\Http\ObservingTransport;
use Laraflow\Support\ExecutionScope;
use Laraflow\Support\LoggingContext;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\WorkflowExecution;
use RuntimeException;
use Throwable;

/**
 * The outermost layer on its own, with a clock the inner transport
 * advances: the duration is the caller's wait, the context is restored
 * on both exits, and exactly one event describes the call.
 */
class ObservingTransportTest extends TestCase
{
    protected FixedClock $clock;

    /**
     * @var array<string, mixed>
     */
    protected array $contextDuringSend = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-11-03 12:00:00');
        Event::fake([ConnectorRequestSent::class, ConnectorRequestFailed::class]);
    }

    public function test_a_response_is_announced_once_with_the_whole_duration_and_attempts(): void
    {
        $response = $this->transport(ConnectorResponse::fake(201, ['id' => 1])->withAttempts(3))
            ->send(new ConnectorRequest('post', '/contacts?token=leak'), new HttpOptions);

        $this->assertSame(201, $response->status());
        Event::assertNotDispatched(ConnectorRequestFailed::class);
        Event::assertDispatchedTimes(ConnectorRequestSent::class, 1);
        Event::assertDispatched(ConnectorRequestSent::class, fn (ConnectorRequestSent $e): bool => $e->connector === 'crm'
            && $e->connection === 'eu'
            && $e->method === 'POST'
            && $e->path === '/contacts'
            && $e->durationMs === 1500
            && $e->attempts === 3);
    }

    public function test_a_failure_is_announced_with_the_exceptions_attempt_count_and_rethrown(): void
    {
        $failure = TransportException::connecting('crm.example', new RuntimeException('refused'))->afterAttempts(4);

        try {
            $this->transport($failure)->send(new ConnectorRequest('get', '/contacts'), new HttpOptions);
            $this->fail('The failure should propagate.');
        } catch (TransportException $caught) {
            $this->assertSame($failure, $caught);
        }

        Event::assertNotDispatched(ConnectorRequestSent::class);
        Event::assertDispatched(ConnectorRequestFailed::class, fn (ConnectorRequestFailed $e): bool => $e->errorClass === TransportException::class
            && $e->durationMs === 1500
            && $e->attempts === 4);
    }

    public function test_both_events_name_the_running_executions_tenant_and_only_that(): void
    {
        $scope = $this->app->make(ExecutionScope::class);

        // Outside any execution: no tenant to name, and none invented.
        $this->transport(ConnectorResponse::fake(200))->send(new ConnectorRequest('get', '/a'), new HttpOptions);
        Event::assertDispatched(ConnectorRequestSent::class, fn (ConnectorRequestSent $e): bool => $e->path === '/a' && $e->tenantId === null);

        $scope->enter(new WorkflowExecution(id: 1, workflow: 'App\\Workflows\\Shared', tenantId: 'acme'));

        try {
            $this->transport(ConnectorResponse::fake(200))->send(new ConnectorRequest('get', '/b'), new HttpOptions);

            try {
                $this->transport(new RuntimeException('boom'))->send(new ConnectorRequest('get', '/c'), new HttpOptions);
            } catch (RuntimeException) {
            }

            // An execution created without a tenant is the same as none.
            $scope->enter(new WorkflowExecution(id: 2, workflow: 'App\\Workflows\\Shared', tenantId: null));
            $this->transport(ConnectorResponse::fake(200))->send(new ConnectorRequest('get', '/d'), new HttpOptions);
            $scope->leave();
        } finally {
            $scope->leave();
        }

        Event::assertDispatched(ConnectorRequestSent::class, fn (ConnectorRequestSent $e): bool => $e->path === '/b' && $e->tenantId === 'acme');
        Event::assertDispatched(ConnectorRequestFailed::class, fn (ConnectorRequestFailed $e): bool => $e->path === '/c' && $e->tenantId === 'acme');
        Event::assertDispatched(ConnectorRequestSent::class, fn (ConnectorRequestSent $e): bool => $e->path === '/d' && $e->tenantId === null);
    }

    public function test_any_other_throwable_counts_as_one_attempt(): void
    {
        try {
            $this->transport(new RuntimeException('boom'))->send(new ConnectorRequest('get', '/'), new HttpOptions);
        } catch (RuntimeException) {
        }

        Event::assertDispatched(ConnectorRequestFailed::class, fn (ConnectorRequestFailed $e): bool => $e->attempts === 1);
    }

    public function test_the_connector_is_in_the_log_context_during_the_call_and_gone_after_either_exit(): void
    {
        $context = $this->app->make(ContextRepository::class);

        $this->transport(ConnectorResponse::fake())->send(new ConnectorRequest('get', '/'), new HttpOptions);
        $this->assertSame('crm', $this->seen('connector'));
        $this->assertSame('eu', $this->seen('connection'));
        $this->assertFalse($context->has(LoggingContext::PREFIX.'connector'));

        $this->contextDuringSend = [];

        try {
            $this->transport(new RuntimeException('boom'))->send(new ConnectorRequest('get', '/'), new HttpOptions);
        } catch (RuntimeException) {
        }

        $this->assertSame('crm', $this->seen('connector'));
        $this->assertFalse($context->has(LoggingContext::PREFIX.'connector'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordContext(array $context): void
    {
        $this->contextDuringSend = $context;
    }

    protected function transport(ConnectorResponse|Throwable $outcome): ObservingTransport
    {
        $context = $this->app->make(ContextRepository::class);

        $inner = new class($outcome, $this->clock, $context, $this) implements HttpTransport
        {
            public function __construct(
                private ConnectorResponse|Throwable $outcome,
                private FixedClock $clock,
                private ContextRepository $context,
                private ObservingTransportTest $test,
            ) {}

            public function send(ConnectorRequest $request, HttpOptions $options): ConnectorResponse
            {
                $this->test->recordContext($this->context->all());
                $this->clock->set($this->clock->now()->modify('+1500 milliseconds'));

                if ($this->outcome instanceof Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };

        return new ObservingTransport(
            $inner,
            new Connection('crm', 'eu'),
            $this->app->make(ConnectorObserver::class),
            $this->clock,
            new LoggingContext($context),
        );
    }

    protected function seen(string $key): mixed
    {
        return $this->contextDuringSend[LoggingContext::PREFIX.$key] ?? null;
    }
}
