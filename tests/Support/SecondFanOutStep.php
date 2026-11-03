<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\FanOut;
use Laraflow\Workflow\FanOutStep;
use Laraflow\Workflow\WorkflowContext;

/**
 * A second fan-out at a second position, so a test can watch one
 * workflow create two groups under the same parent — which is what
 * makes "the members of *this* step" a question with a wrong answer
 * available.
 */
class SecondFanOutStep extends FanOutStep
{
    /** @var list<array<string, mixed>> One member per entry; its input. */
    public static array $inputs = [['m' => 1]];

    public static function reset(): void
    {
        static::$inputs = [['m' => 1]];
    }

    public function members(WorkflowContext $context): iterable
    {
        foreach (static::$inputs as $input) {
            yield new FanOut(new OneStepWorkflow, $input);
        }
    }
}
