<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Tests\Support\FakeHttpConnector;
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

                return new ConnectorResponse(299, [], 'from the replacement');
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

    protected function laraflow(): LaraflowManager
    {
        return $this->app->make(LaraflowManager::class);
    }
}
