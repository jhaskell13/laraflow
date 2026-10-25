<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Exceptions\ConnectorException;
use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Tests\TestCase;

class LaravelIntegrationTest extends TestCase
{
    public function test_core_services_are_container_singletons(): void
    {
        foreach ([ConnectorFactory::class, ConnectorManager::class, LaraflowManager::class] as $abstract) {
            $this->assertSame(
                $this->app->make($abstract),
                $this->app->make($abstract),
                "{$abstract} should resolve to the same instance."
            );
        }
    }

    public function test_an_application_can_register_a_connector_from_a_service_provider(): void
    {
        $connector = $this->fakeConnector('crm');

        $this->app->make(LaraflowManager::class)->register('crm', fn () => $connector);

        $laraflow = $this->app->make(LaraflowManager::class);

        $this->assertTrue($laraflow->has('crm'));
        $this->assertSame($connector, $laraflow->connector('crm'));
    }

    public function test_connectors_are_built_lazily_and_cached(): void
    {
        $built = 0;

        $this->app->make(LaraflowManager::class)->register('counting', function () use (&$built) {
            $built++;

            return $this->fakeConnector('counting');
        });

        $laraflow = $this->app->make(LaraflowManager::class);

        $this->assertSame(0, $built, 'Registering must not build the connector.');

        $first = $laraflow->connector('counting');
        $second = $laraflow->connector('counting');

        $this->assertSame(1, $built, 'The factory must run once.');
        $this->assertSame($first, $second);
    }

    public function test_resolving_an_unknown_connector_throws_a_package_exception(): void
    {
        $this->expectException(ConnectorNotRegisteredException::class);

        $this->app->make(LaraflowManager::class)->connector('nope');
    }

    public function test_the_bundled_github_connector_uses_the_configured_token(): void
    {
        config(['laraflow.integrations.github.token' => 'configured-token']);

        Http::fake(['api.github.com/*' => Http::response(['login' => 'octocat'])]);

        $connector = $this->app->make(LaraflowManager::class)->connector('github');

        $this->assertInstanceOf(GithubConnector::class, $connector);

        $response = $connector->request(new ConnectorRequest('GET', '/user'));

        $this->assertSame('octocat', $response->body()['login']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer configured-token'));
    }

    /**
     * Characterisation, recorded before Milestone 5 changes it: a
     * connector captures its token at construction and is cached for the
     * application lifetime, so rotating the configured credential has
     * no effect until the process restarts. Step 3 of the milestone
     * inverts this assertion.
     */
    public function test_a_built_connector_keeps_the_credentials_it_was_built_with(): void
    {
        config(['laraflow.integrations.github.token' => 'first-token']);

        Http::fake(['api.github.com/*' => Http::response([])]);

        $laraflow = $this->app->make(LaraflowManager::class);

        $laraflow->connector('github')->request(new ConnectorRequest('GET', '/user'));

        config(['laraflow.integrations.github.token' => 'rotated-token']);

        $laraflow->connector('github')->request(new ConnectorRequest('GET', '/user'));

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer rotated-token'));
    }

    public function test_the_bundled_github_connector_fails_clearly_when_no_token_is_configured(): void
    {
        config(['laraflow.integrations.github.token' => null]);

        try {
            $this->app->make(LaraflowManager::class)->connector('github');
            $this->fail('Expected ConnectorException.');
        } catch (ConnectorException $exception) {
            $this->assertStringContainsString('LARAFLOW_GITHUB_TOKEN', $exception->getMessage());
        }
    }

    protected function fakeConnector(string $name): Connector
    {
        return new class($name) implements Connector
        {
            public function __construct(private readonly string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function request(ConnectorRequest $request): ConnectorResponse
            {
                return new ConnectorResponse(status: 200, headers: [], body: null);
            }
        };
    }
}
