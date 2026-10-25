<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Tests\TestCase;

class GithubConnectorTest extends TestCase
{
    public function test_it_sends_an_authenticated_json_request_to_the_github_api(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['id' => 123, 'name' => 'laraflow'], 200),
        ]);

        $response = $this->connector()->request(
            new ConnectorRequest('GET', '/repos/example/laraflow', ['query' => ['per_page' => 5]])
        );

        $this->assertTrue($response->successful());
        $this->assertSame(123, $response->body()['id']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/repos/example/laraflow?per_page=5'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_connection_failure_is_translated_without_leaking_credentials(): void
    {
        Http::fake([
            'api.github.com/*' => fn () => throw new ConnectionException('Connection failed'),
        ]);

        try {
            $this->connector()->request(new ConnectorRequest('GET', '/user'));
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
            $this->assertStringNotContainsString('test-token', $exception->getMessage());
        }
    }

    public function test_http_errors_are_returned_as_failed_responses_not_exceptions(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $response = $this->connector()->request(new ConnectorRequest('GET', '/repos/example/missing'));

        $this->assertSame(404, $response->status());
        $this->assertTrue($response->failed());
        $this->assertSame('Not Found', $response->body()['message']);
    }

    public function test_non_json_bodies_are_returned_as_null(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response('<html>maintenance</html>', 503, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->connector()->request(new ConnectorRequest('GET', '/user'));

        $this->assertSame(503, $response->status());
        $this->assertNull($response->body());
    }

    protected function connector(): GithubConnector
    {
        return new GithubConnector(
            new Connection('github', 'default', ['token' => 'test-token']),
            $this->app->make(HttpClient::class),
        );
    }
}
