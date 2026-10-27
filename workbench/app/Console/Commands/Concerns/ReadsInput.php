<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Concerns;

/**
 * Console input narrowed to strings.
 *
 * `argument()` and `option()` are typed `array|bool|float|int|string|null`
 * because a signature may declare any of them, so casting the result
 * straight to string is a cast the analyser cannot check. These read
 * the ones these commands declare as strings and say what happens to
 * anything else.
 */
trait ReadsInput
{
    /**
     * A string argument, or '' when it is absent or not a string.
     */
    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * A string option, trimmed; null when absent, blank, or not a
     * string — which is what every caller here treats as "unset".
     */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
