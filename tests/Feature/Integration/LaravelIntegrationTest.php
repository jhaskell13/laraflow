<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Exceptions\ConnectionNotConfiguredException;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Exceptions\ConnectorException;
use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Exceptions\InvalidConnectorException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRegistry;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\Credentials;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Tests\Support\RecordingConnector;
use Laraflow\Tests\TestCase;

class LaravelIntegrationTest extends TestCase
{
    public function test_core_services_are_container_singletons(): void
    {
        foreach ([ConnectorRegistry::class, LaraflowManager::class] as $abstract) {
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
        $this->assertContains('crm', $laraflow->connectors());
    }

    public function test_an_application_can_register_a_connector_class_in_configuration(): void
    {
        config(['laraflow.integrations.crm' => [
            'connector' => RecordingConnector::class,
            'connections' => ['default' => ['region' => 'eu']],
        ]]);

        // Boot again: configuration-driven registration happens in boot().
        $this->app->register(\Laraflow\Core\LaraflowServiceProvider::class, force: true);

        $connector = $this->app->make(LaraflowManager::class)->connector('crm');

        $this->assertInstanceOf(RecordingConnector::class, $connector);
        $this->assertSame('eu', $connector->connection->get('region'));
    }

    public function test_a_misnamed_connector_class_in_configuration_fails_at_boot(): void
    {
        config(['laraflow.integrations.crm' => ['connector' => 'App\\Nope']]);

        $this->expectException(InvalidConnectorException::class);

        $this->app->register(\Laraflow\Core\LaraflowServiceProvider::class, force: true);
    }

    public function test_named_connections_share_one_connector_class(): void
    {
        config(['laraflow.integrations.github.connections' => [
            'default' => ['credentials' => ['token' => 'default-token']],
            'enterprise' => ['credentials' => ['token' => 'enterprise-token']],
        ]]);

        Http::fake(['api.github.com/*' => Http::response([])]);

        $laraflow = $this->app->make(LaraflowManager::class);

        $this->assertSame(['default', 'enterprise'], $laraflow->connections('github'));

        $laraflow->connector('github')->request(new ConnectorRequest('GET', '/user'));
        $laraflow->connector('github', 'enterprise')->request(new ConnectorRequest('GET', '/user'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer default-token'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer enterprise-token'));
    }

    public function test_an_unknown_connection_is_refused(): void
    {
        $this->expectException(ConnectionNotConfiguredException::class);

        $this->app->make(LaraflowManager::class)->connector('github', 'staging');
    }

    public function test_the_milestone_0_token_layout_is_refused_with_guidance(): void
    {
        config(['laraflow.integrations.github' => ['connector' => GithubConnector::class, 'token' => 'ghp_x']]);

        try {
            $this->app->make(LaraflowManager::class)->connector('github');
            $this->fail('Expected ConnectorConfigurationException.');
        } catch (ConnectorConfigurationException $exception) {
            $this->assertStringContainsString('laraflow.integrations.github.connections.default', $exception->getMessage());
            $this->assertStringNotContainsString('ghp_x', $exception->getMessage());
        }
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
        config(['laraflow.integrations.github.connections.default.credentials.token' => 'configured-token']);

        Http::fake(['api.github.com/*' => Http::response(['login' => 'octocat'])]);

        $connector = $this->app->make(LaraflowManager::class)->connector('github');

        $this->assertInstanceOf(GithubConnector::class, $connector);

        $response = $connector->request(new ConnectorRequest('GET', '/user'));

        $this->assertSame('octocat', $response->body()['login']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer configured-token'));
    }

    /**
     * Inverts the Milestone 5 step 1 characterisation: credentials are
     * resolved when a request is built, so a rotated token is used by
     * the next request without the connector being rebuilt.
     */
    public function test_a_rotated_credential_is_used_by_the_next_request(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => 'first-token']);

        Http::fake(['api.github.com/*' => Http::response([])]);

        $laraflow = $this->app->make(LaraflowManager::class);

        $laraflow->connector('github')->request(new ConnectorRequest('GET', '/user'));

        config(['laraflow.integrations.github.connections.default.credentials.token' => 'rotated-token']);

        $laraflow->connector('github')->request(new ConnectorRequest('GET', '/user'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer first-token'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer rotated-token'));
    }

    public function test_an_application_can_supply_credentials_from_its_own_provider(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => null]);

        $this->app->instance(CredentialProvider::class, new class implements CredentialProvider
        {
            public function credentials(Connection $connection): Credentials
            {
                return new Credentials($connection, ['token' => 'vault-'.$connection->name]);
            }
        });

        Http::fake(['api.github.com/*' => Http::response([])]);

        $this->app->make(LaraflowManager::class)->connector('github')->request(new ConnectorRequest('GET', '/user'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer vault-default'));
    }

    public function test_the_connection_a_connector_holds_carries_no_credentials(): void
    {
        config(['laraflow.integrations.github.connections.default' => [
            'credentials' => ['token' => 'ghp_x'],
            'base_url' => 'https://ghe.example',
        ]]);

        $connector = $this->app->make(LaraflowManager::class)->connector('github');

        $this->assertInstanceOf(GithubConnector::class, $connector);
        $this->assertSame(['base_url' => 'https://ghe.example'], $connector->connection()->config);
    }

    public function test_the_bundled_github_connector_fails_clearly_when_no_token_is_configured(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => null]);

        Http::fake();

        // Resolving succeeds — the connector holds no credential to
        // check. The first request is where a missing one is found.
        $connector = $this->app->make(LaraflowManager::class)->connector('github');

        try {
            $connector->request(new ConnectorRequest('GET', '/user'));
            $this->fail('Expected ConnectorException.');
        } catch (ConnectorException $exception) {
            $this->assertStringContainsString('LARAFLOW_GITHUB_TOKEN', $exception->getMessage());
            $this->assertStringContainsString('connections.default.credentials.token', $exception->getMessage());
        }

        Http::assertNothingSent();
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
