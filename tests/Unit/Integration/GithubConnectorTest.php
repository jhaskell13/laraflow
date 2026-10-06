<?php

namespace Laraflow\Tests\Unit\Integration;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Core\LaraflowServiceProvider;
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
            'GET',
            '/repos/example/laraflow'
        );

        $this->assertTrue($response->successful());

        $this->assertSame(123, $response->json('id'));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.github.com/repos/example/laraflow'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }
}