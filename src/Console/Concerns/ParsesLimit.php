<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use InvalidArgumentException;
use Laraflow\Workflow\ExecutionRepository;

/**
 * @internal The --limit option, read one way by every command that
 * declares it: a positive integer, bounded above by the repository's
 * own page ceiling, with the values that are not a number of anything
 * refused by name.
 *
 * @mixin \Illuminate\Console\Command
 */
trait ParsesLimit
{
    /**
     * @throws InvalidArgumentException
     */
    protected function limitOption(int $default): int
    {
        $value = $this->option('limit');

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException("The --limit option must be a positive integer, got [{$value}].");
        }

        return min((int) $value, ExecutionRepository::MAX_PAGE_SIZE);
    }
}
