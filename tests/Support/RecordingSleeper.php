<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Support\Sleeper;

/**
 * Records the waits a retry loop asks for instead of serving them.
 */
class RecordingSleeper implements Sleeper
{
    /**
     * @var list<int>
     */
    public array $sleeps = [];

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
