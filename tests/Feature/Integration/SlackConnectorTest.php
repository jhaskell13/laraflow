<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\ClientErrorException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\Slack\SlackConnector;
use Laraflow\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The second real connector, proving the generic contracts against a
 * provider that behaves nothing like the first.
 */
class SlackConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['laraflow.integrations.slack.connections.default.credentials.token' => 'xoxb-test-token']);
    }

    public function test_a_read_is_an_authenticated_get_with_its_arguments_as_the_query(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channels' => []])]);

        $slack = $this->slack();
        $response = $slack->request($slack->read('conversations.list', ['limit' => 200, 'types' => 'public_channel']));

        $this->assertTrue($response->successful());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://slack.com/api/conversations.list?limit=200&types=public_channel'
            && $request->hasHeader('Authorization', 'Bearer xoxb-test-token')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_a_write_is_a_post_with_a_json_body_and_is_not_idempotent(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'ts' => '1.2'])]);

        $slack = $this->slack();
        $request = $slack->write('chat.postMessage', ['channel' => 'C1', 'text' => 'hello']);

        $this->assertFalse($request->isIdempotent(), 'Posting a message twice posts two messages.');

        $slack->request($request);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === 'https://slack.com/api/chat.postMessage'
            && $r->data() === ['channel' => 'C1', 'text' => 'hello']);
    }

    public function test_a_200_carrying_ok_false_is_a_failure(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => 'channel_not_found'])]);

        $slack = $this->slack();
        $response = $slack->request($slack->write('chat.postMessage', ['channel' => 'C9']));

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->failed(), 'Status alone would call this a success.');
        $this->assertInstanceOf(NotFoundException::class, $response->error());
        $this->assertSame(
            'Connector [slack:default] received 200 for POST /chat.postMessage: channel_not_found',
            $response->error()?->getMessage(),
        );
    }

    #[DataProvider('errorCodes')]
    public function test_slack_error_codes_map_to_the_shared_exception_types(string $code, string $expected): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => $code])]);

        $slack = $this->slack();

        $this->assertInstanceOf($expected, $slack->request($slack->read('auth.test'))->error());
    }

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function errorCodes(): array
    {
        return [
            'invalid_auth' => ['invalid_auth', AuthenticationFailedException::class],
            'token_revoked' => ['token_revoked', AuthenticationFailedException::class],
            'missing_scope' => ['missing_scope', AuthenticationFailedException::class],
            'channel_not_found' => ['channel_not_found', NotFoundException::class],
            'user_not_found' => ['user_not_found', NotFoundException::class],
            'ratelimited' => ['ratelimited', RateLimitedException::class],
            'anything else' => ['invalid_arguments', ClientErrorException::class],
        ];
    }

    public function test_validation_detail_from_response_metadata_reaches_the_message(): void
    {
        Http::fake(['slack.com/*' => Http::response([
            'ok' => false,
            'error' => 'invalid_arguments',
            'response_metadata' => ['messages' => ['[ERROR] missing required field: channel']],
        ])]);

        $slack = $this->slack();
        $error = $slack->request($slack->write('chat.postMessage'))->error();

        $this->assertStringContainsString('invalid_arguments ([ERROR] missing required field: channel)', (string) $error?->getMessage());
    }

    public function test_a_429_still_maps_by_status_and_carries_the_providers_wait(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '30'])]);

        $slack = $this->slack();
        $error = $slack->request($slack->read('users.list'))->error();

        $this->assertInstanceOf(RateLimitedException::class, $error);
        $this->assertSame(30, $error->retryAfterSeconds(new DateTimeImmutable));
    }

    public function test_an_ok_true_response_is_a_success_and_a_bodiless_one_is_not_misread(): void
    {
        Http::fake(['slack.com/*' => Http::response('', 200, ['Content-Type' => 'text/plain'])]);

        $slack = $this->slack();

        $this->assertTrue($slack->request($slack->read('files.get'))->successful(), 'No envelope is not a failure.');
    }

    public function test_cursor_pagination_walks_every_page_of_a_named_listing(): void
    {
        Http::fake([
            'slack.com/*' => Http::sequence()
                ->push(['ok' => true, 'members' => [['id' => 'U1']], 'response_metadata' => ['next_cursor' => 'c2']])
                ->push(['ok' => true, 'members' => [['id' => 'U2']], 'response_metadata' => ['next_cursor' => '']]),
        ]);

        $slack = $this->slack();

        $members = iterator_to_array($slack->items(
            $slack->read('users.list', ['limit' => 200]),
            paginator: $slack->listing('members'),
        ));

        $this->assertSame([['id' => 'U1'], ['id' => 'U2']], $members);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cursor=c2')
            && str_contains($request->url(), 'limit=200'));
    }

    public function test_a_failed_page_stops_pagination(): void
    {
        Http::fake([
            'slack.com/*' => Http::sequence()
                ->push(['ok' => true, 'members' => [['id' => 'U1']], 'response_metadata' => ['next_cursor' => 'c2']])
                ->push(['ok' => false, 'error' => 'invalid_auth']),
        ]);

        $slack = $this->slack();

        $this->expectException(AuthenticationFailedException::class);

        iterator_to_array($slack->items($slack->read('users.list'), paginator: $slack->listing('members')));
    }

    public function test_slack_is_unversioned_and_says_so(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $slack = $this->slack();
        $slack->request($slack->read('auth.test'));

        $this->assertNull($slack->providerApiVersion());
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Slack-Api-Version'));
    }

    public function test_it_serves_several_workspaces_from_one_class(): void
    {
        config(['laraflow.integrations.slack.connections' => [
            'default' => ['credentials' => ['token' => 'xoxb-acme']],
            'globex' => ['credentials' => ['token' => 'xoxb-globex']],
        ]]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $laraflow = $this->app->make(LaraflowManager::class);

        $laraflow->connector('slack')->request(new ConnectorRequest('GET', '/auth.test'));
        $laraflow->connector('slack', 'globex')->request(new ConnectorRequest('GET', '/auth.test'));

        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer xoxb-acme'));
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer xoxb-globex'));
    }

    protected function slack(): SlackConnector
    {
        $connector = $this->app->make(LaraflowManager::class)->connector('slack');
        $this->assertInstanceOf(SlackConnector::class, $connector);

        return $connector;
    }
}
