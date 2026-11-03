<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

/**
 * A workflow whose plan a test sets, used to put two fan-out steps
 * under one parent.
 */
class TwoFanOutWorkflow extends Workflow
{
    /** @var list<class-string<\Laraflow\Workflow\Step>> */
    public static array $steps = [NotifyMembersStep::class, SecondFanOutStep::class];

    public static function reset(): void
    {
        static::$steps = [NotifyMembersStep::class, SecondFanOutStep::class];
    }

    public function steps(): array
    {
        return static::$steps;
    }
}
