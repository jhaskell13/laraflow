<?php

namespace Laraflow\Tests\Unit\Integration;

use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorManager;

class ConnectorManagerTest extends TestCase
{
    public function test_it_can_register_and_retrieve_a_connector(): void
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
}