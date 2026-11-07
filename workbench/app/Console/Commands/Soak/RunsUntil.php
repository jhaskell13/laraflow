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
     * @param  Closure(int $tick): void  $tick
     */
    protected function runUntil(int $durationSeconds, float $everySeconds, Closure $tick, ?Closure $onError = null): void
    {
        $deadline = $durationSeconds > 0 ? microtime(true) + $durationSeconds : PHP_FLOAT_MAX;
        $n = 0;

        while (microtime(true) < $deadline) {
            $startedAt = microtime(true);

            try {
                $tick(++$n);
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
