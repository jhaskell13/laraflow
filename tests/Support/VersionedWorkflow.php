<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\PlanDraft;
use Laraflow\Workflow\Workflow;

/**
 * A workflow whose plan and declared version both move under the test,
 * standing in for a deploy that changed a workflow while executions
 * were in flight.
 */
class VersionedWorkflow extends Workflow
{
    /** @var list<class-string<\Laraflow\Workflow\Step>> */
    public static array $steps = [];

    public static int $version = 1;

    /** @var array<int, callable(PlanDraft): mixed> */
    public static array $migrations = [];

    public static function reset(): void
    {
        static::$steps = [StepOne::class, StepTwo::class];
        static::$version = 1;
        static::$migrations = [];
    }

    public function steps(): array
    {
        return static::$steps;
    }

    public function version(): int
    {
        return static::$version;
    }

    public function planMigrations(): array
    {
        return static::$migrations;
    }
}
