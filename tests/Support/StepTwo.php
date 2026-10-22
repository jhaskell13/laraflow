<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

class StepTwo implements Step
{
    public static int $runs = 0;

    public static int $failTimes = 0;

    /** @var string[] */
    public static array $seenIdempotencyKeys = [];

    public static mixed $seenContextValue = null;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        static::$seenIdempotencyKeys[] = $context->idempotencyKey();
        static::$seenContextValue = $context->get('one');

        if (static::$failTimes > 0) {
            static::$failTimes--;

            throw new RuntimeException('step two blew up');
        }

        $context->set('two', 'from-step-two');

        return ['n' => 2];
    }
}
