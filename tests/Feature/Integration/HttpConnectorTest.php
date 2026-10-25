<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Exceptions\Connector\ClientErrorException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Exceptions\Connector\ServerErrorException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\Http\ErrorMapper;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\Http\StatusErrorMapper;
use Laraflow\Integration\Http\TransportPipeline;
use Laraflow\Integration\HttpConnector;
use Laraflow\Support\Sleeper;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\RecordingSleeper;
use Laraflow\Tests\TestCase;

class HttpConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['laraflow.integrations.crm' => [
            'connector' => FakeHttpConnector::class,
            'connections' => [
                'default' => ['credentials' => ['token' => 'crm-token']],
                'eu' => [
                    'base_url' => 'https://eu.crm.example/api',
                    'timeout' => 5,
                    'headers' => ['X-Region' => 'eu'],
                    'credentials' => ['token' => 'eu-token'],
                ],
            ],
        ]]);

        $this->app->register(\Laraflow\Core\LaraflowServiceProvider::class, force: true);
    }

    public function test_a_subclass_needs_only_a_base_url_to_make_authenticated_requests(): void
    {
        Http::fake(['crm.example/*' => Http::response(['id' => 1])]);

        $connector = $this->laraflow()->connector('crm');

        $this->assertSame('crm', $connector->name());

        $response = $connector->request(new ConnectorRequest('GET', '/contacts/1'));

        $this->assertSame(['id' => 1], $response->body());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crm.example/api/contacts/1'
            && $request->hasHeader('Authorization', 'Bearer crm-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('X-Client', 'laraflow'));
    }

    public function test_a_connection_overrides_the_host_timeout_and_headers_of_the_class(): void
    {
        $seen = [];

        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([]);
        });

        $this->laraflow()->connector('crm', 'eu')->request(new ConnectorRequest('GET', '/contacts'));

        $this->assertSame(5.0, $seen['timeout']);
        $this->assertSame(10.0, $seen['connect_timeout'], 'Not overridden: the laraflow.http default.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.crm.example/api/contacts'
            && $request->hasHeader('Authorization', 'Bearer eu-token')
            && $request->hasHeader('X-Region', 'eu')
            && $request->hasHeader('X-Client', 'laraflow'));
    }

    public function test_the_fleet_wide_defaults_come_from_configuration(): void
    {
        config(['laraflow.http.timeout' => 42]);
        $this->app->forgetInstance(HttpOptions::class);

        $seen = [];

        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([]);
        });

        $this->laraflow()->connector('crm')->request(new ConnectorRequest('GET', '/contacts'));

        $this->assertSame(42.0, $seen['timeout']);
    }

    public function test_an_application_can_replace_the_transport(): void
    {
        $sent = [];

        $this->app->instance(HttpTransport::class, new class($sent) implements HttpTransport
        {
            /**
             * @param  list<array{ConnectorRequest, HttpOptions}>  $sent
             */
            public function __construct(public array &$sent) {}

            public function send(ConnectorRequest $request, HttpOptions $options): ConnectorResponse
            {
                $this->sent[] = [$request, $options];

                return new ConnectorResponse(299, ['Content-Type' => 'text/plain'], 'from the replacement');
            }
        });

        $response = $this->laraflow()->connector('crm', 'eu')->request(new ConnectorRequest('POST', '/contacts', ['json' => ['name' => 'x']]));

        $this->assertSame('from the replacement', $response->body());
        $this->assertCount(1, $sent);

        [$request, $options] = $sent[0];

        $this->assertSame('Bearer eu-token', $request->options['headers']['Authorization'], 'The transport receives the authenticated request.');
        $this->assertSame(['name' => 'x'], $request->options['json']);
        $this->assertSame('https://eu.crm.example/api', $options->baseUrl);
    }

    public function test_an_error_response_is_returned_with_its_mapped_exception_attached(): void
    {
        Http::fake(['crm.example/*' => Http::response(['message' => 'No such contact'], 404)]);

        $response = $this->laraflow()->connector('crm')->request(new ConnectorRequest('GET', '/contacts/9?expand=all'));

        $this->assertTrue($response->failed());
        $this->assertInstanceOf(NotFoundException::class, $response->error());
        $this->assertSame(404, $response->error()?->status());
        $this->assertSame(
            'Connector [crm:default] received 404 for GET /contacts/9: No such contact',
            $response->error()?->getMessage(),
        );

        try {
            $response->throw();
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $exception) {
            $this->assertSame($response->error(), $exception);
            $this->assertSame(['message' => 'No such contact'], $exception->response->body());
        }
    }

    public function test_a_successful_response_has_no_error_and_throw_returns_it(): void
    {
        Http::fake(['crm.example/*' => Http::response(['id' => 1])]);

        $response = $this->laraflow()->connector('crm')->request(new ConnectorRequest('GET', '/contacts/1'));

        $this->assertNull($response->error());
        $this->assertSame($response, $response->throw());
    }

    public function test_a_connection_can_ask_for_errors_to_be_thrown(): void
    {
        config(['laraflow.integrations.crm.connections.default.throw_on_error' => true]);
        $this->laraflow()->forget('crm');

        Http::fake(['crm.example/*' => Http::response('down', 503)]);

        $this->expectException(ServerErrorException::class);

        $this->laraflow()->connector('crm')->request(new ConnectorRequest('GET', '/contacts'));
    }

    public function test_a_connector_supplies_the_mapper_that_knows_its_provider(): void
    {
        $this->laraflow()->register('okfalse', fn (Connection $connection): Connector => new class($connection, $this->app->make(CredentialProvider::class), $this->app->make(HttpTransport::class), $this->app->make(HttpOptions::class), $this->app->make(TransportPipeline::class)) extends HttpConnector
        {
            protected function baseUrl(): ?string
            {
                return 'https://okfalse.example';
            }

            protected function errorMapper(): ErrorMapper
            {
                return new class extends StatusErrorMapper
                {
                    public function map(ConnectorRequest $request, ConnectorResponse $response, Connection $connection): ?RequestFailedException
                    {
                        if ($response->status() === 200 && $response->json('ok') === false) {
                            return ClientErrorException::fromResponse($request, $response, $connection, $response->json('error'));
                        }

                        return parent::map($request, $response, $connection);
                    }
                };
            }
        });

        config(['laraflow.integrations.okfalse.connections.default.credentials.token' => 't']);

        Http::fake(['okfalse.example/*' => Http::response(['ok' => false, 'error' => 'channel_not_found'])]);

        $response = $this->laraflow()->connector('okfalse')->request(new ConnectorRequest('POST', '/chat.postMessage'));

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->failed(), 'A 200 the mapper rejects is a failure.');
        $this->assertInstanceOf(ClientErrorException::class, $response->error());
        $this->assertStringContainsString('channel_not_found', (string) $response->error()?->getMessage());
    }

    public function test_transport_retries_are_off_by_default(): void
    {
        Http::fake(['crm.example/*' => Http::sequence()->push('busy', 503)->push(['id' => 1])]);

        $response = $this->laraflow()->connector('crm')->request(new ConnectorRequest('GET', '/contacts/1'));

        $this->assertSame(503, $response->status());
        $this->assertSame(1, $response->attempts());
        Http::assertSentCount(1);
    }

    public function test_a_connection_can_enable_transport_retries(): void
    {
        config(['laraflow.integrations.crm.connections.default.retries' => ['max_attempts' => 3, 'base_delay_ms' => 1, 'jitter' => 0]]);
        $this->app->instance(Sleeper::class, $sleeper = new RecordingSleeper);
        $this->app->forgetInstance(TransportPipeline::class);
        $this->laraflow()->forget('crm');

        Http::fake(['crm.example/*' => Http::sequence()->push('busy', 503)->push('busy', 503)->push(['id' => 1])]);

        $response = $this->laraflow()->connector('crm')->request(new ConnectorRequest('GET', '/contacts/1'));

        $this->assertSame(200, $response->status());
        $this->assertSame(3, $response->attempts());
        $this->assertSame([1, 2], $sleeper->sleeps);
        Http::assertSentCount(3);
    }

    protected function laraflow(): LaraflowManager
    {
        return $this->app->make(LaraflowManager::class);
    }
}
