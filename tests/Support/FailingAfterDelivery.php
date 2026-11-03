<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\ChildDelivery;
use RuntimeException;

/**
 * A ChildDelivery that dies on the Nth hand-over, standing in for a
 * worker killed part-way through creating a fan-out's members: the
 * members before it exist and were delivered, the one it died on
 * exists and was not, and nothing after it exists at all.
 */
final class FailingAfterDelivery implements ChildDelivery
{
    public int $deliveries = 0;

    public function __construct(
        private readonly ChildDelivery $inner,
        public int $dieOn,
    ) {}

    public function deliver(int $executionId): void
    {
        $this->deliveries++;

        if ($this->deliveries === $this->dieOn) {
            throw new RuntimeException("Worker died delivering member #{$this->deliveries}.");
        }

        $this->inner->deliver($executionId);
    }
}
