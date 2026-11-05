<?php

declare(strict_types=1);

namespace Laraflow\StatsD;

/**
 * Where datagrams go. UdpTransport in production; a test records them.
 */
interface Transport
{
    public function send(string $datagram): void;
}
