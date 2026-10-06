<?php

namespace Laraflow\Tests\Unit\Integration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\Github\GithubConnector;
use Orchestra\Testbench\TestCase;
use Override;

class GithubConnectorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaraflowServiceProvider::class,
        ];
    }

    public function test_it_can_make_a_github_request(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'id' => 123,
                'name' => 'laraflow',
            ], 200),
        ]);

        $connector = new GithubConnector(new GithubTokenAuthenticator('test-token'));

        $response = $connector->request(
            new ConnectorRequest(
                'GET',
                '/repos/example/laraflow'
            )
        );

        $this->assertTrue($response->successful());

        $this->assertSame(123, $response->body()['id']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.github.com/repos/example/laraflow'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_connection_failure_is_translated_to_transport_exception(): void
    {
        Http::fake([
            'api.github.com/*' => fn () => throw new ConnectionException('Connection failed'),
        ]);

        $connector = new GitHubConnector(
            new GitHubTokenAuthenticator('test-token')
        );

        $this->expectException(TransportException::class);

        $connector->request(
            new ConnectorRequest(
                'GET',
                '/repos/example/laraflow'
            )
        );
    }

    public function test_http_error_is_returned_as_a_connector_response(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $connector = new GitHubConnector(
            new GitHubTokenAuthenticator('test-token')
        );

        $response = $connector->request(
            new ConnectorRequest(
                'GET',
                '/repos/example/does-not-exist'
            )
        );

        $this->assertSame(404, $response->status());
        $this->assertTrue($response->failed());
        $this->assertSame(
            'Not Found',
            $response->body()['message']
        );
    }
}