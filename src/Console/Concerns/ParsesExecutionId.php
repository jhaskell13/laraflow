<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use InvalidArgumentException;

/**
 * @internal Parses the `execution` argument the inspect-and-operate
 * commands share. Execution ids are integers on every Laraflow
 * signature (docs/public-api.md), so a value that is not a positive
 * integer is refused here, naming the argument, rather than reaching
 * the repository as a type error or as a lookup that can only ever
 * answer "not found".
 *
 * @mixin \Illuminate\Console\Command
 */
trait ParsesExecutionId
{
    /**
     * @throws InvalidArgumentException when the argument is not a positive integer.
     */
    protected function executionIdArgument(string $name = 'execution'): int
    {
        $value = $this->argument($name);
        $value = is_scalar($value) ? trim((string) $value) : '';

        // The round trip through (int) refuses leading zeros, signs,
        // whitespace, and anything past PHP_INT_MAX, which (int) would
        // otherwise clamp to a real-looking id.
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1 || (string) (int) $value !== $value) {
            throw new InvalidArgumentException(
                "The {$name} argument must be a positive integer execution id, got [{$value}]."
            );
        }

        return (int) $value;
    }
}
