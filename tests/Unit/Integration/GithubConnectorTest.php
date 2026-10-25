<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\ServerErrorException;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\Credentials;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\LaravelHttpTransport;
use Laraflow\Integration\Http\TransportPipeline;
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
            && $request->hasHeader('Accept', 'application/vnd.github+json')
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
        $this->assertInstanceOf(NotFoundException::class, $response->error());
    }

    public function test_non_json_bodies_are_returned_as_received(): void
    {
        // Until Milestone 5 this was null, indistinguishable from a JSON null.
        Http::fake([
            'api.github.com/*' => Http::response('<html>maintenance</html>', 503, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->connector()->request(new ConnectorRequest('GET', '/user'));

        $this->assertSame(503, $response->status());
        $this->assertSame('<html>maintenance</html>', $response->body());
        $this->assertInstanceOf(ServerErrorException::class, $response->error());
    }

    public function test_an_exhausted_rate_limit_is_rate_limiting_not_an_authentication_failure(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
                'X-RateLimit-Remaining' => '0',
                'Retry-After' => '60',
            ]),
        ]);

        $response = $this->connector()->request(new ConnectorRequest('GET', '/user'));

        $error = $response->error();

        $this->assertInstanceOf(RateLimitedException::class, $error);
        $this->assertSame(60, $error->retryAfterSeconds(new DateTimeImmutable));
        $this->assertStringContainsString('API rate limit exceeded', $error->getMessage());
    }

    public function test_a_plain_403_is_an_authentication_failure(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource not accessible'], 403)]);

        $response = $this->connector()->request(new ConnectorRequest('GET', '/user'));

        $this->assertInstanceOf(AuthenticationFailedException::class, $response->error());
    }

    protected function connector(): GithubConnector
    {
        $credentials = new class implements CredentialProvider
        {
            public function credentials(Connection $connection): Credentials
            {
                return new Credentials($connection, ['token' => 'test-token']);
            }
        };

        return new GithubConnector(
            new Connection('github', 'default'),
            $credentials,
            new LaravelHttpTransport($this->app->make(HttpClient::class)),
            new HttpOptions,
            $this->app->make(TransportPipeline::class),
        );
    }
}
