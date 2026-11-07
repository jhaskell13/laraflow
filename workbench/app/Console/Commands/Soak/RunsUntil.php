<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use Closure;
use Throwable;

/**
 * The shape every soak loop shares: tick until a deadline, or until
 * killed, sleeping between ticks and never letting one tick's
 * exception end the loop — a loop that dies on the first database
 * outage would stop soaking exactly when the soak gets interesting.
 */
trait RunsUntil
{
    /**
     * Tick until the deadline — or until a tick returns `false`, which
     * means it has nothing left to do. Without that, the settling loop
     * went on ticking over a finished run until its ceiling: a soak
     * that settled in two minutes still held a CI job for the
     * remaining forty-five (Milestone 12 audit).
     *
     * @param  Closure(int $tick): (bool|void)  $tick
     */
    protected function runUntil(int $durationSeconds, float $everySeconds, Closure $tick, ?Closure $onError = null): void
    {
        $deadline = $durationSeconds > 0 ? microtime(true) + $durationSeconds : PHP_FLOAT_MAX;
        $n = 0;

        while (microtime(true) < $deadline) {
            $startedAt = microtime(true);

            try {
                if ($tick(++$n) === false) {
                    return;
                }
            } catch (Throwable $exception) {
                if ($onError !== null) {
                    $onError($exception);
                }
            }

            $sleep = $everySeconds - (microtime(true) - $startedAt);

            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }
    }

    protected function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function floatOption(string $name, float $default): float
    {
        $value = $this->option($name);

        return is_numeric($value) ? (float) $value : $default;
    }
}
