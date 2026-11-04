<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Closure;
use DateTimeImmutable;
use Laraflow\Workflow\MissedTickPolicy;
use Laraflow\Workflow\Schedule;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\Workflow;

/**
 * A scheduled workflow whose declaration is whatever the test says it
 * is — the MutableWorkflow pattern, for schedules.
 */
class NightlyWorkflow extends Workflow
{
    public static string $cron = '0 2 * * *';

    public static ?string $timezone = null;

    public static MissedTickPolicy $policy = MissedTickPolicy::Skip;

    public static bool $withoutOverlapping = false;

    public static ?string $tenant = null;

    /** @var (Closure(DateTimeImmutable): mixed)|array<string, mixed>|null */
    public static Closure|array|null $input = null;

    /** @var list<class-string<Step>> */
    public static array $steps = [StepOne::class];

    public static function reset(): void
    {
        self::$cron = '0 2 * * *';
        self::$timezone = null;
        self::$policy = MissedTickPolicy::Skip;
        self::$withoutOverlapping = false;
        self::$tenant = null;
        self::$input = null;
        self::$steps = [StepOne::class];
    }

    public function steps(): array
    {
        return self::$steps;
    }

    public function schedule(): ?Schedule
    {
        $schedule = Schedule::cron(self::$cron)->onMissedTicks(self::$policy);

        if (self::$timezone !== null) {
            $schedule = $schedule->timezone(self::$timezone);
        }

        if (self::$withoutOverlapping) {
            $schedule = $schedule->withoutOverlapping();
        }

        if (self::$tenant !== null) {
            $schedule = $schedule->tenant(self::$tenant);
        }

        if (self::$input !== null) {
            $schedule = $schedule->input(self::$input);
        }

        return $schedule;
    }
}
