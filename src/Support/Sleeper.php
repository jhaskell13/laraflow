<?php

declare(strict_types=1);

namespace Laraflow\Support;

/**
 * Pauses the current process. Bound by interface so a test can record
 * the waits a retry loop asks for instead of serving them.
 *
 * @internal Default binding is SystemSleeper.
 */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
