<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;

class ConnectorsCommandTest extends TestCase
{
    use RunsArtisanCommands;

    protected function setUp(): void
    {
        parent::setUp();

        config(['laraflow.integrations' => [
            'github' => [
                'connector' => \Laraflow\Integration\Github\GithubConnector::class,
                'connections' => ['default' => ['credentials' => ['token' => 'ghp_secret_value']]],
            ],
            'crm' => [
                'connector' => FakeHttpConnector::class,
                'connections' => [
                    'default' => ['credentials' => ['token' => 'crm-secret']],
                    'sandbox' => [
                        'base_url' => 'https://sandbox.crm.example/api',
                        'timeout' => 5,
                        'retries' => ['max_attempts' => 3],
                        'rate_limit' => ['max' => 30, 'per' => 60],
                    ],
                ],
            ],
        ]]);

        $this->app->register(LaraflowServiceProvider::class, force: true);
    }

    public function test_it_lists_every_connector_and_connection_with_its_configuration(): void
    {
        $output = $this->callCommand('laraflow:connectors')['output'];

        $this->assertStringContainsString('github', $output);
        $this->assertStringContainsString('https://api.github.com', $output);
        $this->assertStringContainsString('crm', $output);
        $this->assertStringContainsString('sandbox', $output);
        $this->assertStringContainsString('https://sandbox.crm.example/api', $output);
        $this->assertStringContainsString('5s', $output);
        $this->assertStringContainsString('3 attempts', $output);
        $this->assertStringContainsString('30/60s', $output);
        $this->assertStringContainsString('off', $output, 'A connection without retries says so.');
        $this->assertStringContainsString('2022-11-28', $output, 'The pinned GitHub API version.');
        $this->assertStringContainsString('unversioned', $output, 'A provider without versions says so.');
    }

    public function test_it_names_credentials_without_printing_them(): void
    {
        $output = $this->callCommand('laraflow:connectors')['output'];

        $this->assertStringContainsString('token', $output);
        $this->assertStringNotContainsString('ghp_secret_value', $output);
        $this->assertStringNotContainsString('crm-secret', $output);
        $this->assertStringContainsString('none', $output, 'The sandbox connection has no credentials.');
    }

    public function test_it_can_show_one_connector(): void
    {
        $output = $this->callCommand('laraflow:connectors', ['connector' => 'github'])['output'];

        $this->assertStringContainsString('github', $output);
        $this->assertStringNotContainsString('sandbox', $output);
    }

    public function test_an_unknown_connector_is_refused(): void
    {
        ['status' => $exit, 'output' => $output] = $this->callCommand('laraflow:connectors', ['connector' => 'nope']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[nope] is not registered', $output);
    }

    public function test_check_exits_non_zero_when_a_connection_cannot_be_built(): void
    {
        config(['laraflow.integrations.crm.connections.broken' => ['base_url' => 'not a url']]);

        ['status' => $exit, 'output' => $output] = $this->callCommand('laraflow:connectors', ['--check' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[crm:broken]', $output);
        $this->assertStringContainsString('not a url', $output);
    }

    public function test_check_succeeds_when_everything_resolves(): void
    {
        ['status' => $exit, 'output' => $output] = $this->callCommand('laraflow:connectors', ['--check' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Every connector resolved', $output);
    }

    public function test_listing_survives_one_broken_connection(): void
    {
        config(['laraflow.integrations.crm.connections.broken' => ['base_url' => 'not a url']]);

        ['status' => $exit, 'output' => $output] = $this->callCommand('laraflow:connectors');

        $this->assertSame(0, $exit, 'Listing what exists must work even when one connection is misconfigured.');
        $this->assertStringContainsString('github', $output);
        $this->assertStringContainsString('[crm:broken]', $output);
    }

    public function test_it_says_so_when_nothing_is_registered(): void
    {
        config(['laraflow.integrations' => []]);
        $this->app->register(LaraflowServiceProvider::class, force: true);
        $this->app->make(\Laraflow\Core\LaraflowManager::class)->forget();

        $output = $this->callCommand('laraflow:connectors')['output'];

        $this->assertStringContainsString('No connectors are registered', $output);
    }
}
