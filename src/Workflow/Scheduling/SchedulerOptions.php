<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * @internal The scheduler's configured numbers and flags, read and
 * validated once — the RunnerOptions pattern. Blank environment values
 * mean "use the documented default", as everywhere in the package.
 */
final readonly class SchedulerOptions
{
    public function __construct(
        public bool $enabled,
        public int $maxCatchUp,
        public int $catchUpWindowSeconds,
        public int $redispatchAfterSeconds,
        public int $limit,
    ) {
        if ($maxCatchUp < 1) {
            throw new InvalidArgumentException('laraflow.schedules.max_catch_up must be at least 1.');
        }

        if ($catchUpWindowSeconds < 1) {
            throw new InvalidArgumentException('laraflow.schedules.catch_up_window must be at least 1 second.');
        }

        if ($redispatchAfterSeconds < 1) {
            throw new InvalidArgumentException('laraflow.schedules.redispatch_after must be at least 1 second.');
        }

        if ($limit < 1) {
            throw new InvalidArgumentException('laraflow.schedules.limit must be at least 1.');
        }
    }

    public static function fromConfig(Config $config): self
    {
        $setting = static function (string $key, int $default) use ($config): int {
            $value = $config->get($key);

            return $value === null || $value === '' ? $default : (int) $value;
        };

        return new self(
            enabled: (bool) ($config->get('laraflow.schedules.enabled') ?? true),
            maxCatchUp: $setting('laraflow.schedules.max_catch_up', 10),
            catchUpWindowSeconds: $setting('laraflow.schedules.catch_up_window', 86400),
            redispatchAfterSeconds: $setting('laraflow.schedules.redispatch_after', 300),
            limit: $setting('laraflow.schedules.limit', 100),
        );
    }
}
