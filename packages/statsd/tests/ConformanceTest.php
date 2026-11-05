<?php

declare(strict_types=1);

namespace Laraflow\StatsD\Tests;

use InvalidArgumentException;
use Laraflow\StatsD\StatsDSink;
use Laraflow\StatsD\UdpTransport;
use Laraflow\Testing\MetricsConformance;

/**
 * docs/metrics.md, rule 2, read from the datagrams themselves: both
 * dialects, with and without the tenant label.
 */
final class ConformanceTest extends TestCase
{
    public function test_both_dialects_send_exactly_the_documented_families(): void
    {
        foreach (['dogstatsd', 'graphite'] as $dialect) {
            foreach ([false, true] as $tenantLabel) {
                $kit = new MetricsConformance($tenantLabel);
                $transport = new RecordingTransport;

                $kit->replay(new StatsDSink($transport, $dialect));

                $kit->assertExposes($this->families($transport->sent, $dialect));
            }
        }
    }

    public function test_the_wire_format_is_what_collectors_parse(): void
    {
        $dog = new RecordingTransport;
        $graphite = new RecordingTransport;
        (new MetricsConformance)->replay(new StatsDSink($dog));
        (new MetricsConformance)->replay(new StatsDSink($graphite, 'graphite'));

        $w = MetricsConformance::WORKFLOW;

        $this->assertContains("laraflow_executions_finished_total:1|c|#workflow:{$w},outcome:completed", $dog->sent);
        $this->assertContains("laraflow_execution_duration_seconds:1.5|h|#workflow:{$w},outcome:completed", $dog->sent);
        $this->assertContains('laraflow_retries_due:1|g', $dog->sent);
        // An empty label value is dropped, not sent empty.
        $this->assertContains('laraflow_connector_requests_total:1|c|#connector:crm,connection:default,method:GET,outcome:failed', $dog->sent);
        $this->assertContains("laraflow_executions_finished_total;workflow={$w};outcome=completed:1|c", $graphite->sent);
    }

    public function test_tag_separators_in_a_value_cannot_forge_a_tag(): void
    {
        $transport = new RecordingTransport;
        $catalog = (new MetricsConformance)->catalog();

        (new StatsDSink($transport))->increment($catalog->get('laraflow_signals_received_total'), ['workflow' => 'A,tenant:evil|#x']);
        (new StatsDSink($transport, 'graphite'))->increment($catalog->get('laraflow_signals_received_total'), ['workflow' => 'A;tenant=evil~x']);

        $this->assertSame([
            // One tag: DogStatsD splits a tag at its first colon, and a lone # is
            // harmless once the | that would open a section is gone.
            'laraflow_signals_received_total:1|c|#workflow:A_tenant:evil_#x',
            'laraflow_signals_received_total;workflow=A_tenant=evil_x:1|c',
        ], $transport->sent);
    }

    public function test_an_unknown_dialect_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StatsDSink(new RecordingTransport, 'plain');
    }

    public function test_datagrams_really_cross_udp(): void
    {
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        $this->assertNotFalse($server, (string) $error);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        (new UdpTransport('127.0.0.1', $port))->send('laraflow_retries_due:3|g');

        $this->assertSame('laraflow_retries_due:3|g', stream_socket_recvfrom($server, 1500));
    }

    public function test_an_unreachable_collector_never_throws(): void
    {
        (new UdpTransport('256.0.0.1', 8125))->send('laraflow_retries_due:3|g');

        $this->addToAssertionCount(1);
    }
}
