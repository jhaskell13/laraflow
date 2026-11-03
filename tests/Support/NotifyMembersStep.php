<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\FanOut;
use Laraflow\Workflow\FanOutStep;
use Laraflow\Workflow\WorkflowContext;

/**
 * A fan-out whose members a test declares: one TwoStepWorkflow child
 * per input. Counts how often members() is read, since the contract
 * says once.
 */
class NotifyMembersStep extends FanOutStep
{
    /** @var list<array<string, mixed>> One member per entry; its input. */
    public static array $inputs = [['n' => 1], ['n' => 2], ['n' => 3]];

    /** @var class-string<\Laraflow\Workflow\Workflow> */
    public static string $workflow = TwoStepWorkflow::class;

    public static int $membersCalls = 0;

    /** When set, yielded as a member in place of a FanOut, to be refused. */
    public static mixed $yieldInstead = null;

    public static function reset(): void
    {
        static::$inputs = [['n' => 1], ['n' => 2], ['n' => 3]];
        static::$workflow = TwoStepWorkflow::class;
        static::$membersCalls = 0;
        static::$yieldInstead = null;
    }

    public function members(WorkflowContext $context): iterable
    {
        static::$membersCalls++;

        if (static::$yieldInstead !== null) {
            yield static::$yieldInstead;

            return;
        }

        foreach (static::$inputs as $input) {
            yield new FanOut(new (static::$workflow), $input);
        }
    }
}
