<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\MappingTransport;
use Laraflow\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MappingTransportTest extends TestCase
{
    public function test_the_inner_response_passes_through_the_map_and_the_maps_result_is_returned(): void
    {
        $inner = new ScriptedTransport([ConnectorResponse::fake(200, ['ok' => true])]);
        $mapped = ConnectorResponse::fake(200, ['mapped' => true]);
        $seen = null;

        $transport = new MappingTransport($inner, function (ConnectorResponse $response) use (&$seen, $mapped): ConnectorResponse {
            $seen = $response;

            return $mapped;
        });

        $request = new ConnectorRequest('GET', '/x');

        $this->assertSame($mapped, $transport->send($request, new HttpOptions));
        $this->assertSame(['ok' => true], $seen?->json());
        $this->assertSame([$request], $inner->sent);
    }

    public function test_a_transport_failure_is_not_mapped(): void
    {
        $failure = TransportException::connecting('api.example.com', new RuntimeException('refused'));
        $mapped = false;

        $transport = new MappingTransport(new ScriptedTransport([$failure]), function (ConnectorResponse $response) use (&$mapped): ConnectorResponse {
            $mapped = true;

            return $response;
        });

        try {
            $transport->send(new ConnectorRequest('GET', '/x'), new HttpOptions);
            $this->fail('The failure should propagate.');
        } catch (TransportException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertFalse($mapped);
    }
}
