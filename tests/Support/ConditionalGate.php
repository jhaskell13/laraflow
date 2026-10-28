<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\ConditionalStep;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use Throwable;

class ConditionalGate implements ConditionalStep, Step
{
    public static bool $open = true;

    public static int $runs = 0;

    public static ?Throwable $throw = null;

    /** How many times shouldRun() was consulted. */
    public static int $asked = 0;

    /** When set, the condition is "this context key exists" instead of $open. */
    public static ?string $readsKey = null;

    public function shouldRun(WorkflowContext $context): bool
    {
        static::$asked++;

        if (static::$throw !== null) {
            $throw = static::$throw;
            static::$throw = null;

            throw $throw;
        }

        return static::$readsKey !== null ? $context->has(static::$readsKey) : static::$open;
    }

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        $context->set('gate', 'opened');

        return ['gate' => true];
    }
}
