<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\LaravelHttpTransport;
use Laraflow\Tests\TestCase;
use ReflectionMethod;
use SensitiveParameter;

class LaravelHttpTransportTest extends TestCase
{
    public function test_it_applies_the_base_url_timeouts_and_default_headers(): void
    {
        $seen = [];

        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response(['ok' => true]);
        });

        $response = $this->transport()->send(
            new ConnectorRequest('GET', '/users', ['query' => ['page' => 2]]),
            new HttpOptions('https://api.example/v1', 7.5, 2.5, ['Accept' => 'application/json']),
        );

        $this->assertTrue($response->successful());
        $this->assertSame(['ok' => true], $response->body());
        $this->assertSame(7.5, $seen['timeout']);
        $this->assertSame(2.5, $seen['connect_timeout']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.example/v1/users?page=2'
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_request_headers_win_over_defaults_without_becoming_lists(): void
    {
        Http::fake();

        $this->transport()->send(
            new ConnectorRequest('GET', '/x', ['headers' => ['Accept' => 'text/plain']]),
            new HttpOptions('https://api.example', headers: ['Accept' => 'application/json', 'X-Client' => 'laraflow']),
        );

        Http::assertSent(fn (Request $request): bool => $request->header('Accept') === ['text/plain']
            && $request->header('X-Client') === ['laraflow']);
    }

    public function test_request_headers_win_whatever_their_casing(): void
    {
        Http::fake();

        $this->transport()->send(
            new ConnectorRequest('GET', '/x', ['headers' => ['accept' => 'text/plain']]),
            new HttpOptions('https://api.example', headers: ['Accept' => 'application/json']),
        );

        // One header on the wire, the request's value — not a pair of
        // differently spelled Accepts for the provider to pick from.
        Http::assertSent(fn (Request $request): bool => $request->header('accept') === ['text/plain']
            && count($request->headers()) === count(array_unique(array_map('strtolower', array_keys($request->headers())))));
    }

    public function test_without_a_base_url_the_uri_is_sent_as_given(): void
    {
        Http::fake();

        $this->transport()->send(new ConnectorRequest('GET', 'https://other.example/ping'), new HttpOptions);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://other.example/ping');
    }

    public function test_a_connection_failure_becomes_a_transport_exception_naming_only_the_host(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to api.example port 443 ?token=leak'));

        try {
            $this->transport()->send(
                new ConnectorRequest('GET', '/users', ['headers' => ['Authorization' => 'Bearer leak-token'], 'query' => ['token' => 'leak']]),
                new HttpOptions('https://api.example'),
            );
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertSame('Unable to connect to api.example.', $exception->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        }
    }

    public function test_the_authenticated_request_is_marked_sensitive(): void
    {
        $parameter = (new ReflectionMethod(LaravelHttpTransport::class, 'send'))->getParameters()[0];

        $this->assertNotEmpty($parameter->getAttributes(SensitiveParameter::class));
    }

    protected function transport(): LaravelHttpTransport
    {
        return new LaravelHttpTransport($this->app->make(HttpClient::class));
    }
}
