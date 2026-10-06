<?php

namespace Laraflow\Tests\Feature\Integration;

use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Core\LaraflowManager;
use Orchestra\Testbench\TestCase;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Github\GithubConnector;

class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaraflowServiceProvider::class,
        ];
    }

    public function test_connector_manager_is_registered_with_laravel(): void
    {
        $manager = $this->app->make(ConnectorManager::class);

        $this->assertInstanceOf(ConnectorManager::class, $manager);
    }

    public function test_laravel_can_register_and_resolve_a_connector(): void
    {
        $connector = new class implements Connector {
            public function name(): string 
            {
                return 'test';
            }

            public function request(ConnectorRequest $request): ConnectorResponse
            {
                return new ConnectorResponse(
                    status: 200,
                    headers: [],
                    body: null
                );
            }
        };

        $manager = $this->app->make(ConnectorManager::class);

        $manager->register('test', $connector);

        $this->assertSame($connector, $manager->get('test'));
    }

    public function test_laraflow_manager_is_registered_with_laravel(): void
    {
        $laraflow = $this->app->make(LaraflowManager::class);

        $this->assertInstanceOf(LaraflowManager::class, $laraflow);
    }

    public function test_laraflow_can_resolve_a_registered_connector(): void
    {
        $connector = new class implements Connector {
            public function name(): string 
            {
                return 'test';
            }

            public function request(ConnectorRequest $request): ConnectorResponse
            {
                return new ConnectorResponse(
                    status: 200,
                    headers: [],
                    body: null
                );
            }
        };  

        $manager = $this->app->make(ConnectorManager::class);

        $manager->register('test', $connector);

        $laraflow = $this->app->make(LaraflowManager::class);

        $this->assertSame($connector, $laraflow->connector('test'));
    }

    public function test_laraflow_can_resolve_github_connector(): void
    {
        $github = new GithubConnector(new GithubTokenAuthenticator('test-token'));

        $manager = $this->app->make(ConnectorManager::class);

        $manager->register('github', $github);

        $laraflow = $this->app->make(LaraflowManager::class);

        $this->assertSame(
            $github,
            $laraflow->connector('github')
        );
    }

    public function test_laraflow_can_create_github_connector(): void
    {
        config([
            'laraflow.integrations.github.token' => 'test-token',
        ]);

        $laraflow = $this->app->make(LaraflowManager::class);

        $connector = $laraflow->connector('github');

        $this->assertInstanceOf(
            GitHubConnector::class,
            $connector
        );

        $this->assertSame(
            'github',
            $connector->name()
        );
    }

    public function test_laraflow_reuses_the_same_connector_instance(): void
    {
        config([
            'laraflow.integrations.github.token' => 'test-token',
        ]);

        $laraflow = $this->app->make(LaraflowManager::class);

        $first = $laraflow->connector('github');
        $second = $laraflow->connector('github');

        $this->assertSame($first, $second);
    }
}