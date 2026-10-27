<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Laraflow\Exceptions\ConnectionNotConfiguredException;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Exceptions\InvalidConnectorException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRegistry;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Tests\Support\RecordingConnector;
use PHPUnit\Framework\TestCase;
use stdClass;

class ConnectorRegistryTest extends TestCase
{
    public function test_a_closure_is_run_lazily_once_per_connection_and_receives_the_connection(): void
    {
        $registry = $this->registry([
            'crm' => ['connections' => ['eu' => ['region' => 'eu'], 'us' => ['region' => 'us']]],
        ]);
        $built = [];

        $registry->register('crm', function (Connection $connection) use (&$built): Connector {
            $built[] = $connection->key();

            return new RecordingConnector($connection);
        });

        $this->assertSame([], $built, 'Registering must not build anything.');

        $eu = $registry->resolve('crm', 'eu');
        $us = $registry->resolve('crm', 'us');

        $this->assertSame($eu, $registry->resolve('crm', 'eu'));
        $this->assertNotSame($eu, $us);
        $this->assertSame(['crm:eu', 'crm:us'], $built);
        $this->assertInstanceOf(RecordingConnector::class, $eu);
        $this->assertSame('eu', $eu->connection->get('region'));
    }

    public function test_a_class_is_built_through_the_container_with_the_connection_and_its_own_dependencies(): void
    {
        $container = new Container;
        $container->instance(stdClass::class, $dependency = new stdClass);

        $registry = $this->registry([
            'crm' => ['connections' => ['default' => ['region' => 'eu']]],
        ], $container);

        $registry->register('crm', RecordingConnector::class);

        $connector = $registry->resolve('crm');

        $this->assertInstanceOf(RecordingConnector::class, $connector);
        $this->assertSame('crm:default', $connector->connection->key());
        $this->assertSame($dependency, $connector->dependency, 'Other constructor parameters are autowired.');
    }

    public function test_a_connector_with_no_configuration_resolves_an_empty_default_connection(): void
    {
        $registry = $this->registry([]);
        $registry->register('crm', RecordingConnector::class);

        $connector = $registry->resolve('crm');
        $this->assertInstanceOf(RecordingConnector::class, $connector);

        $this->assertSame([], $connector->connection->config);
        $this->assertSame('default', $connector->connection->name);
        $this->assertSame(['default'], $registry->connections('crm'));
    }

    public function test_the_default_connection_comes_from_configuration(): void
    {
        $registry = $this->registry([
            'github' => [
                'default' => 'enterprise',
                'connections' => ['default' => [], 'enterprise' => ['host' => 'ghe']],
            ],
        ]);
        $registry->register('github', RecordingConnector::class);

        $this->assertSame('enterprise', $registry->defaultConnection('github'));

        $github = $registry->resolve('github');
        $this->assertInstanceOf(RecordingConnector::class, $github);
        $this->assertSame('ghe', $github->connection->get('host'));
        $this->assertSame(['default', 'enterprise'], $registry->connections('github'));
    }

    public function test_a_blank_default_means_the_conventional_name(): void
    {
        $registry = $this->registry(['github' => ['default' => '', 'connections' => ['default' => []]]]);
        $registry->register('github', RecordingConnector::class);

        $this->assertSame('default', $registry->defaultConnection('github'));
    }

    public function test_an_unknown_connector_is_refused(): void
    {
        $registry = $this->registry([]);

        $this->assertFalse($registry->has('nope'));
        $this->expectException(ConnectorNotRegisteredException::class);

        $registry->resolve('nope');
    }

    public function test_an_unknown_connection_is_refused_naming_the_configured_ones(): void
    {
        $registry = $this->registry(['github' => ['connections' => ['default' => [], 'enterprise' => []]]]);
        $registry->register('github', RecordingConnector::class);

        try {
            $registry->resolve('github', 'staging');
            $this->fail('Expected ConnectionNotConfiguredException.');
        } catch (ConnectionNotConfiguredException $exception) {
            $this->assertStringContainsString('[github]', $exception->getMessage());
            $this->assertStringContainsString('[staging]', $exception->getMessage());
            $this->assertStringContainsString('default, enterprise', $exception->getMessage());
            $this->assertStringContainsString('laraflow.integrations.github.connections.staging', $exception->getMessage());
        }
    }

    public function test_a_named_connection_on_an_unconfigured_connector_is_refused(): void
    {
        $registry = $this->registry([]);
        $registry->register('crm', RecordingConnector::class);

        $this->expectException(ConnectionNotConfiguredException::class);

        $registry->resolve('crm', 'eu');
    }

    public function test_a_setting_left_at_the_connector_level_is_refused_with_the_path_it_belongs_at(): void
    {
        // The Milestone 0 layout. Reading it as nothing would leave an
        // operator with a configured token and an unauthenticated
        // connector; refusing it names the line to change.
        $registry = $this->registry(['github' => ['token' => 'ghp_x']]);
        $registry->register('github', RecordingConnector::class);

        try {
            $registry->resolve('github');
            $this->fail('Expected ConnectorConfigurationException.');
        } catch (ConnectorConfigurationException $exception) {
            $this->assertStringContainsString('laraflow.integrations.github.token is not a recognised key', $exception->getMessage());
            $this->assertStringContainsString('laraflow.integrations.github.connections.default', $exception->getMessage());
            $this->assertStringNotContainsString('ghp_x', $exception->getMessage());
        }
    }

    public function test_malformed_connections_are_refused(): void
    {
        $registry = $this->registry(['github' => ['connections' => ['default' => 'ghp_x']]]);
        $registry->register('github', RecordingConnector::class);

        $this->expectException(ConnectorConfigurationException::class);

        $registry->resolve('github');
    }

    public function test_a_class_that_is_not_a_connector_is_refused_at_registration(): void
    {
        $registry = $this->registry([]);

        try {
            $registry->register('bad', stdClass::class);
            $this->fail('Expected InvalidConnectorException.');
        } catch (InvalidConnectorException $exception) {
            $this->assertStringContainsString('[bad]', $exception->getMessage());
            $this->assertStringContainsString('stdClass', $exception->getMessage());
        }

        $this->assertFalse($registry->has('bad'));
    }

    public function test_a_closure_that_does_not_return_a_connector_is_reported_with_the_actual_type(): void
    {
        $registry = $this->registry([]);
        // Deliberately invalid: the registry must report the actual type.
        /** @phpstan-ignore argument.type */
        $registry->register('invalid', fn (): stdClass => new stdClass);

        try {
            $registry->resolve('invalid');
            $this->fail('Expected InvalidConnectorException.');
        } catch (InvalidConnectorException $exception) {
            $this->assertStringContainsString('[invalid]', $exception->getMessage());
            $this->assertStringContainsString('stdClass', $exception->getMessage());
        }
    }

    public function test_invalid_names_are_refused(): void
    {
        $registry = $this->registry([]);

        foreach (['', 'has space', "new\nline"] as $name) {
            try {
                $registry->register($name, RecordingConnector::class);
                $this->fail("Expected [{$name}] to be refused.");
            } catch (ConnectorConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_forget_drops_instances_at_every_scope(): void
    {
        $registry = $this->registry([
            'crm' => ['connections' => ['eu' => [], 'us' => []]],
            'erp' => [],
        ]);
        $registry->register('crm', RecordingConnector::class);
        $registry->register('erp', RecordingConnector::class);

        $eu = $registry->resolve('crm', 'eu');
        $us = $registry->resolve('crm', 'us');
        $erp = $registry->resolve('erp');

        $registry->forget('crm', 'eu');
        $this->assertNotSame($eu, $registry->resolve('crm', 'eu'));
        $this->assertSame($us, $registry->resolve('crm', 'us'));

        $registry->forget('crm');
        $this->assertNotSame($us, $registry->resolve('crm', 'us'));
        $this->assertSame($erp, $registry->resolve('erp'));

        $registry->forget();
        $this->assertNotSame($erp, $registry->resolve('erp'));
    }

    public function test_re_registering_replaces_the_definition_and_its_instances(): void
    {
        $registry = $this->registry([]);
        $registry->register('crm', RecordingConnector::class);
        $first = $registry->resolve('crm');

        $replacement = new class implements Connector
        {
            public function name(): string
            {
                return 'crm';
            }

            public function request(ConnectorRequest $request): ConnectorResponse
            {
                return ConnectorResponse::fake();
            }
        };

        $registry->register('crm', fn (): Connector => $replacement);

        $this->assertNotSame($first, $registry->resolve('crm'));
        $this->assertSame($replacement, $registry->resolve('crm'));
    }

    public function test_names_are_sorted(): void
    {
        $registry = $this->registry([]);
        $registry->register('slack', RecordingConnector::class);
        $registry->register('github', RecordingConnector::class);

        $this->assertSame(['github', 'slack'], $registry->names());
    }

    /**
     * @param  array<string, mixed>  $integrations
     */
    protected function registry(array $integrations, ?Container $container = null): ConnectorRegistry
    {
        return new ConnectorRegistry(
            $container ?? new Container,
            new Repository(['laraflow' => ['integrations' => $integrations]]),
        );
    }
}
