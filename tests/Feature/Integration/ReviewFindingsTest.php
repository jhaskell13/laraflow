<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laraflow\Core\LaraflowManager;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\LaravelHttpTransport;
use Laraflow\Integration\Http\TransportPipeline;
use Laraflow\Integration\Pagination\LinkHeaderPaginator;
use Laraflow\Integration\Slack\SlackConnector;
use Laraflow\Support\Sleeper;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\RecordingSleeper;
use Laraflow\Tests\TestCase;

/**
 * Defects found reviewing Milestone 5 before closing it, each pinned.
 */
class ReviewFindingsTest extends TestCase
{
    public function test_a_transport_failure_without_a_base_url_names_the_host_not_the_uri(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        try {
            (new LaravelHttpTransport($this->app->make(HttpClient::class)))->send(
                new ConnectorRequest('GET', 'https://other.example/path?access_token=leak'),
                new HttpOptions,
            );
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertSame('Unable to connect to other.example.', $exception->getMessage());
        }
    }

    public function test_guzzles_own_transfer_failures_become_transport_exceptions(): void
    {
        Http::fake(fn () => throw new TooManyRedirectsException(
            'Will not follow more than 5 redirects to https://api.example/x?token=leak',
            new PsrRequest('GET', 'https://api.example/x?token=leak'),
        ));

        try {
            (new LaravelHttpTransport($this->app->make(HttpClient::class)))->send(
                new ConnectorRequest('GET', '/x', ['query' => ['token' => 'leak']]),
                new HttpOptions('https://api.example'),
            );
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertSame('Unable to connect to api.example.', $exception->getMessage());

            // Laravel wraps Guzzle's exception in its own; the cause is
            // somewhere in the chain, and no link of it is in the message.
            $causes = [];
            for ($e = $exception->getPrevious(); $e !== null; $e = $e->getPrevious()) {
                $causes[] = $e::class;
            }
            $this->assertContains(TooManyRedirectsException::class, $causes);
            $this->assertStringNotContainsString('leak', $exception->getMessage());
        }
    }

    public function test_a_failed_event_after_transport_retries_reports_the_attempts_spent(): void
    {
        config(['laraflow.integrations.crm' => [
            'connector' => FakeHttpConnector::class,
            'connections' => ['default' => [
                'credentials' => ['token' => 't'],
                'retries' => ['max_attempts' => 3, 'base_delay_ms' => 1, 'jitter' => 0],
            ]],
        ]]);
        // Re-registering the provider rebinds the Sleeper, so the recorder
        // goes in afterwards, and the pipeline that captured the old one
        // is dropped.
        $this->app->register(LaraflowServiceProvider::class, force: true);
        $this->app->instance(Sleeper::class, $sleeper = new RecordingSleeper);
        $this->app->forgetInstance(TransportPipeline::class);

        Event::fake([ConnectorRequestFailed::class]);
        Http::fake(fn () => throw new ConnectionException('refused'));

        try {
            $this->app->make(LaraflowManager::class)->connector('crm')->request(new ConnectorRequest('GET', '/x'));
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertSame(3, $exception->attempts);
        }

        Event::assertDispatched(ConnectorRequestFailed::class, fn (ConnectorRequestFailed $event): bool => $event->attempts === 3);

        // A fake that throws records nothing, so the waits are the
        // evidence of the attempts: two sleeps between three tries.
        $this->assertCount(2, $sleeper->sleeps);
    }

    public function test_a_link_header_url_containing_a_comma_is_parsed_whole(): void
    {
        $response = new ConnectorResponse(200, [
            'Link' => '<https://api.example/items?ids=1,2,3&page=2>; rel="next", <https://api.example/items?ids=1,2,3&page=9>; rel="last"',
        ]);

        $next = (new LinkHeaderPaginator)->next(new ConnectorRequest('GET', '/items'), $response);

        $this->assertSame('https://api.example/items?ids=1,2,3&page=2', $next?->uri);
    }

    public function test_a_link_header_with_a_quoted_comma_in_a_parameter_still_finds_next(): void
    {
        $response = new ConnectorResponse(200, [
            'Link' => '<https://api.example/a>; rel="prev"; title="one, two", <https://api.example/b>; rel="next"',
        ]);

        $this->assertSame('https://api.example/b', (new LinkHeaderPaginator)->next(new ConnectorRequest('GET', '/'), $response)?->uri);
    }

    public function test_slacks_mapper_does_not_throw_on_a_malformed_or_non_json_200(): void
    {
        config(['laraflow.integrations.slack.connections.default.credentials.token' => 'xoxb']);

        Http::fake([
            'slack.com/api/files.get' => Http::response('binary-ish', 200, ['Content-Type' => 'application/octet-stream']),
            'slack.com/*' => Http::response('<html>oops</html>', 200, ['Content-Type' => 'application/json']),
        ]);

        $slack = $this->app->make(LaraflowManager::class)->connector('slack');
        $this->assertInstanceOf(SlackConnector::class, $slack);

        $binary = $slack->request($slack->read('files.get'));
        $this->assertTrue($binary->successful());
        $this->assertSame('binary-ish', $binary->body());

        $malformed = $slack->request($slack->read('auth.test'));
        $this->assertNull($malformed->error(), 'A mapper decides, it does not throw; body() reports the decoding problem.');
        $this->expectException(\Laraflow\Exceptions\Connector\ResponseDecodingException::class);
        $malformed->body();
    }

    public function test_a_non_positive_page_budget_is_refused_up_front(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => 't']);
        Http::fake();

        $github = $this->app->make(LaraflowManager::class)->connector('github');
        $this->assertInstanceOf(GithubConnector::class, $github);

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($github->pages(new ConnectorRequest('GET', '/repos'), maxPages: 0));
        Http::assertNothingSent();
    }
}
