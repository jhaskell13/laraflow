<?php

namespace Laraflow\Tests\Unit\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorFactory;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

class ConnectorFactoryTest extends TestCase
{
    public function test_it_can_create_a_connector(): void
    {
        $factory = new ConnectorFactory();

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

        $factory->register(
            'test',
            fn () => $connector
        );

        $result = $factory->make('test');

        $this->assertSame($connector, $result);
    }

    public function test_it_throws_for_unknown_connector(): void
    {
        $factory = new ConnectorFactory();

        $this->expectException(InvalidArgumentException::class);

        $factory->make('unknown');
    }

    public function test_factory_must_return_a_connector(): void
    {
        $factory = new ConnectorFactory();

        $factory->register(
            'invalid',
            fn () => new \stdClass()
        );

        $this->expectException(InvalidArgumentException::class);

        $factory->make('invalid');
    }
}