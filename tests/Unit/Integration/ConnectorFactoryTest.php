<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Exceptions\InvalidConnectorException;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use PHPUnit\Framework\TestCase;
use stdClass;

class ConnectorFactoryTest extends TestCase
{
    public function test_it_builds_a_registered_connector_on_demand(): void
    {
        $factory = new ConnectorFactory;
        $connector = $this->fakeConnector();
        $calls = 0;

        $factory->register('test', function () use ($connector, &$calls) {
            $calls++;

            return $connector;
        });

        $this->assertTrue($factory->has('test'));
        $this->assertSame(0, $calls);
        $this->assertSame($connector, $factory->make('test'));
        $this->assertSame(1, $calls);
    }

    public function test_it_throws_a_package_exception_for_unknown_connectors(): void
    {
        $factory = new ConnectorFactory;

        $this->assertFalse($factory->has('unknown'));
        $this->expectException(ConnectorNotRegisteredException::class);

        $factory->make('unknown');
    }

    public function test_a_factory_that_does_not_return_a_connector_is_reported_with_the_actual_type(): void
    {
        $factory = new ConnectorFactory;
        $factory->register('invalid', fn () => new stdClass);

        try {
            $factory->make('invalid');
            $this->fail('Expected InvalidConnectorException.');
        } catch (InvalidConnectorException $exception) {
            $this->assertStringContainsString('[invalid]', $exception->getMessage());
            $this->assertStringContainsString('stdClass', $exception->getMessage());
        }
    }

    protected function fakeConnector(): Connector
    {
        return new class implements Connector
        {
            public function name(): string
            {
                return 'test';
            }

            public function request(ConnectorRequest $request): ConnectorResponse
            {
                return new ConnectorResponse(status: 200, headers: [], body: null);
            }
        };
    }
}
