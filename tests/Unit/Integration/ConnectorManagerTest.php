<?php

namespace Laraflow\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

class ConnectorManagerTest extends TestCase
{
    public function test_it_can_register_and_retrieve_a_connector(): void
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

        $manager = new ConnectorManager();

        $manager->register('test', $connector);

        $this->assertSame(
            $connector,
            $manager->get('test')
        );
    }

    public function test_it_throws_error_when_connector_does_not_exist(): void
    {
        $manager = new ConnectorManager();

        $this->expectException(\InvalidArgumentException::class);

        $manager->get('does-not-exist');
    }

    public function test_it_can_determine_if_a_connector_is_registered(): void
    {
        $manager = new ConnectorManager();

        $connector = new class implements Connector {
            public function name(): string
            {
                return 'test';
            }

            public function request(
                ConnectorRequest $request
            ): ConnectorResponse {
                return new ConnectorResponse(
                    status: 200,
                    headers: [],
                    body: null,
                );
            }
        };

        $this->assertFalse($manager->has('test'));

        $manager->register('test', $connector);

        $this->assertTrue($manager->has('test'));
    }
}