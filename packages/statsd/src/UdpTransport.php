<?php

declare(strict_types=1);

namespace Laraflow\StatsD;

/**
 * Fire-and-forget UDP, as StatsD intends: a lost datagram is a lost
 * observation, never a stalled worker.
 *
 * The socket is opened on the first send, not at boot — an enabled
 * application boots this bridge for every request and job, and most
 * record nothing. A send that fails is dropped silently: UDP gives no
 * delivery guarantee to report, and a metrics outage must not become a
 * workflow outage (docs/metrics.md, "What every bridge must do").
 */
final class UdpTransport implements Transport
{
    /** @var resource|false|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
    ) {}

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function send(string $datagram): void
    {
        if ($this->socket === null) {
            $socket = @stream_socket_client("udp://{$this->host}:{$this->port}", $errno, $error, 0.0);
            $this->socket = is_resource($socket) ? $socket : false;

            if ($this->socket !== false) {
                stream_set_blocking($this->socket, false);
            }
        }

        if ($this->socket !== false) {
            @fwrite($this->socket, $datagram);
        }
    }
}
