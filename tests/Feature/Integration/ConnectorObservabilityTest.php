<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Support\LoggingContext;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\ScriptedTransport;
use Laraflow\Tests\Support\TokenRedactor;
use Laraflow\Tests\TestCase;

class ConnectorObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['laraflow.integrations.crm' => [
            'connector' => FakeHttpConnector::class,
            'connections' => ['default' => ['credentials' => ['token' => 'crm-token']]],
        ]]);

        $this->app->register(LaraflowServiceProvider::class, force: true);
    }

    public function test_a_response_announces_itself_with_identifiers_and_measurements_only(): void
    {
        Event::fake([ConnectorRequestSent::class, ConnectorRequestFailed::class]);
        Http::fake(['crm.example/*' => Http::response(['id' => 1])]);

        $this->connector()->request(new ConnectorRequest('get', '/contacts/1?token=leak', ['headers' => ['Authorization' => 'Bearer x']]));

        Event::assertNotDispatched(ConnectorRequestFailed::class);
        Event::assertDispatched(ConnectorRequestSent::class, function (ConnectorRequestSent $event): bool {
            $this->assertSame('crm', $event->connector);
            $this->assertSame('default', $event->connection);
            $this->assertSame('GET', $event->method);
            $this->assertSame('/contacts/1', $event->path, 'The query string may carry a credential.');
            $this->assertSame(200, $event->status);
            $this->assertTrue($event->successful);
            $this->assertSame(1, $event->attempts);
            $this->assertGreaterThanOrEqual(0, $event->durationMs);

            // Identifiers and measurements only.
            $this->assertStringNotContainsString('leak', json_encode($event) ?: '');
            $this->assertStringNotContainsString('Bearer', json_encode($event) ?: '');

            return true;
        });
    }

    public function test_an_error_status_is_a_sent_event_reporting_it_unsuccessful(): void
    {
        Event::fake([ConnectorRequestSent::class, ConnectorRequestFailed::class]);
        Http::fake(['crm.example/*' => Http::response(['message' => 'nope'], 404)]);

        $this->connector()->request(new ConnectorRequest('GET', '/contacts/9'));

        Event::assertNotDispatched(ConnectorRequestFailed::class);
        Event::assertDispatched(ConnectorRequestSent::class, fn (ConnectorRequestSent $event): bool => $event->status === 404 && $event->successful === false);
    }

    public function test_no_response_at_all_is_a_failed_event(): void
    {
        Event::fake([ConnectorRequestSent::class, ConnectorRequestFailed::class]);
        Http::fake(['crm.example/*' => fn () => throw new ConnectionException('refused')]);

        try {
            $this->connector()->request(new ConnectorRequest('POST', '/contacts'));
            $this->fail('Expected TransportException.');
        } catch (TransportException) {
            // expected
        }

        Event::assertNotDispatched(ConnectorRequestSent::class);
        Event::assertDispatched(ConnectorRequestFailed::class, function (ConnectorRequestFailed $event): bool {
            $this->assertSame('POST', $event->method);
            $this->assertSame('/contacts', $event->path);
            $this->assertSame(TransportException::class, $event->errorClass);
            $this->assertSame('Unable to connect to crm.example.', $event->error);

            return true;
        });
    }

    public function test_the_configured_redactor_governs_the_failure_message(): void
    {
        config(['laraflow.errors.redactor' => TokenRedactor::class]);
        $this->app->register(LaraflowServiceProvider::class, force: true);

        // A connector that puts provider detail in its transport error:
        // the message must reach the event redacted, as every message
        // Laraflow retains does.
        $this->app->instance(HttpTransport::class, new ScriptedTransport([
            new TransportException('upstream rejected tok_abc123'),
        ]));
        $this->app->make(LaraflowManager::class)->forget('crm');

        Event::fake([ConnectorRequestFailed::class]);

        try {
            $this->connector()->request(new ConnectorRequest('GET', '/contacts'));
            $this->fail('Expected TransportException.');
        } catch (TransportException) {
            // expected
        }

        Event::assertDispatched(ConnectorRequestFailed::class, function (ConnectorRequestFailed $event): bool {
            $this->assertSame('upstream rejected [redacted]', $event->error);
            $this->assertStringNotContainsString('tok_abc123', (string) $event->error);

            return true;
        });
    }

    public function test_messages_can_be_withheld_from_events(): void
    {
        config(['laraflow.events.include_error_messages' => false]);
        $this->app->register(LaraflowServiceProvider::class, force: true);

        Event::fake([ConnectorRequestFailed::class]);
        Http::fake(['crm.example/*' => fn () => throw new ConnectionException('refused')]);

        try {
            $this->connector()->request(new ConnectorRequest('GET', '/contacts'));
        } catch (TransportException) {
            // expected
        }

        Event::assertDispatched(ConnectorRequestFailed::class, fn (ConnectorRequestFailed $event): bool => $event->error === null
            && $event->errorClass === TransportException::class);
    }

    public function test_the_connector_is_named_in_the_log_context_while_the_request_is_in_flight_and_not_after(): void
    {
        $seen = [];
        $context = $this->app->make(ContextRepository::class);

        Http::fake(function () use (&$seen, $context) {
            $seen = $context->all();

            return Http::response([]);
        });

        $context->add('app.request_id', 'r-1');

        $this->connector()->request(new ConnectorRequest('GET', '/contacts'));

        $this->assertSame('crm', $seen[LoggingContext::PREFIX.'connector'] ?? null);
        $this->assertSame('default', $seen[LoggingContext::PREFIX.'connection'] ?? null);
        $this->assertSame('r-1', $seen['app.request_id'] ?? null, "The application's own context is untouched.");

        $this->assertFalse($context->has(LoggingContext::PREFIX.'connector'), 'Restored afterwards.');
        $this->assertFalse($context->has(LoggingContext::PREFIX.'connection'));
        $this->assertTrue($context->has('app.request_id'));
    }

    public function test_the_log_context_is_restored_after_a_failure_too(): void
    {
        $context = $this->app->make(ContextRepository::class);

        Http::fake(['crm.example/*' => fn () => throw new ConnectionException('refused')]);

        try {
            $this->connector()->request(new ConnectorRequest('GET', '/contacts'));
        } catch (TransportException) {
            // expected
        }

        $this->assertFalse($context->has(LoggingContext::PREFIX.'connector'));
    }

    protected function connector(): \Laraflow\Integration\Connector
    {
        return $this->app->make(LaraflowManager::class)->connector('crm');
    }
}
