<?php

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\Response;
use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Core\LaraflowManager;
use Orchestra\Testbench\TestCase;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorManager;
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

            public function request(
                string $method,
                string $uri,
                array $options = []
            ): ?Response {
                return null;
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

            public function request(
                string $method,
                string $uri,
                array $options = []
            ): ?Response {
                return null;
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

        $relay = $this->app->make(LaraflowManager::class);

        $this->assertSame(
            $github,
            $relay->connector('github')
        );
    }
}