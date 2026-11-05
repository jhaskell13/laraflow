<?php

declare(strict_types=1);

namespace Laraflow\StatsD\Tests;

use Laraflow\StatsD\Transport;

final class RecordingTransport implements Transport
{
    /** @var list<string> */
    public array $sent = [];

    public function send(string $datagram): void
    {
        $this->sent[] = $datagram;
    }
}
