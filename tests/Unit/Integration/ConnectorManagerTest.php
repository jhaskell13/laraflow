<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use PHPUnit\Framework\TestCase;

class ConnectorManagerTest extends TestCase
{
    public function test_it_registers_and_retrieves_connector_instances(): void
    {
        $manager = new ConnectorManager;
        $connector = $this->fakeConnector();

        $this->assertFalse($manager->has('test'));

        $manager->register('test', $connector);

        $this->assertTrue($manager->has('test'));
        $this->assertSame($connector, $manager->get('test'));
    }

    public function test_it_throws_a_package_exception_for_unknown_connectors(): void
    {
        $manager = new ConnectorManager;

        $this->expectException(ConnectorNotRegisteredException::class);

        $manager->get('does-not-exist');
    }

    public function test_registering_the_same_name_replaces_the_instance(): void
    {
        $manager = new ConnectorManager;
        $first = $this->fakeConnector();
        $second = $this->fakeConnector();

        $manager->register('test', $first);
        $manager->register('test', $second);

        $this->assertSame($second, $manager->get('test'));
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
