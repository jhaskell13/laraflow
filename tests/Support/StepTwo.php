<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Closure;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

class StepTwo implements Step
{
    public static int $runs = 0;

    public static int $failTimes = 0;

    /**
     * Runs inside handle(), before the step returns and is checkpointed.
     * The counterpart of StepOne::$beforeReturn, for interfering during
     * the *last* step of the two-step workflow.
     */
    public static ?Closure $beforeReturn = null;

    /** @var string[] */
    public static array $seenIdempotencyKeys = [];

    public static mixed $seenContextValue = null;

    /** The correlation id the context exposed on the most recent run. */
    public static ?string $seenCorrelationId = null;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        static::$seenIdempotencyKeys[] = $context->idempotencyKey();
        static::$seenContextValue = $context->get('one');
        static::$seenCorrelationId = $context->correlationId();

        if (static::$failTimes > 0) {
            static::$failTimes--;

            throw new RuntimeException('step two blew up');
        }

        $context->set('two', 'from-step-two');

        if (static::$beforeReturn !== null) {
            (static::$beforeReturn)();
        }

        return ['n' => 2];
    }
}
