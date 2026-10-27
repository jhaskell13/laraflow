<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Workbench\App\Console\Commands\CallCommand;

/**
 * The playground's connector demonstration, run against faked HTTP so
 * the suite stays offline.
 */
class PlaygroundCallCommandTest extends TestCase
{
    use RunsArtisanCommands;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(CallCommand::class));
    }

    public function test_a_missing_credential_is_reported_as_the_configuration_error_it_is(): void
    {
        Http::fake();

        ['status' => $status, 'output' => $output] = $this->callCommand('playground:call', ['connector' => 'github', 'uri' => '/user']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('ConnectorConfigurationException', $output);
        $this->assertStringContainsString('LARAFLOW_GITHUB_TOKEN', $output);
        Http::assertNothingSent();
    }

    public function test_a_success_shows_the_event_and_the_body(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => 'ghp_play']);
        Http::fake(['api.github.com/*' => Http::response(['login' => 'octocat'])]);

        ['status' => $status, 'output' => $output] = $this->callCommand('playground:call', ['connector' => 'github', 'uri' => '/user']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('ConnectorRequestSent', $output);
        $this->assertStringContainsString('octocat', $output);
        $this->assertStringContainsString('GET /user', $output);
        $this->assertStringNotContainsString('ghp_play', $output);
    }

    public function test_an_error_response_is_returned_mapped_and_announced(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => 'ghp_play']);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        ['status' => $status, 'output' => $output] = $this->callCommand('playground:call', [
            'connector' => 'github',
            'uri' => '/repos/nobody/nothing?access_token=ghp_play',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('NotFoundException', $output);
        $this->assertStringContainsString('received 404 for GET /repos/nobody/nothing', $output);
        $this->assertStringNotContainsString('ghp_play', $output, 'Neither the message nor the request line may show the query string.');
    }

    public function test_throw_raises_the_mapped_error(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => 'ghp_play']);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        ['status' => $status, 'output' => $output] = $this->callCommand('playground:call', ['connector' => 'github', 'uri' => '/x', '--throw' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('NotFoundException:', $output);
    }

    public function test_a_connection_failure_is_announced_as_failed(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => 'ghp_play']);
        Http::fake(['api.github.com/*' => fn () => throw new ConnectionException('refused')]);

        ['status' => $status, 'output' => $output] = $this->callCommand('playground:call', ['connector' => 'github', 'uri' => '/user']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('ConnectorRequestFailed', $output);
        $this->assertStringContainsString('TransportException', $output);
    }

    public function test_a_post_carries_the_json_body(): void
    {
        config(['laraflow.integrations.slack.connections.default.credentials.token' => 'xoxb_play']);
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->callCommand('playground:call', ['connector' => 'slack', 'uri' => '/chat.postMessage', '--method' => 'post', '--json' => '{"channel":"C1","text":"hi"}']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->data() === ['channel' => 'C1', 'text' => 'hi']);
    }

    public function test_invalid_json_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        ['status' => $status, 'output' => $output] = $this->callCommand('playground:call', ['connector' => 'slack', 'uri' => '/x', '--json' => '{nope']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('not valid JSON', $output);
        Http::assertNothingSent();
    }
}
