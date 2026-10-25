<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Closure;
use DateTimeImmutable;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

class StepOne implements Step
{
    public static int $runs = 0;

    /** The deadline the context exposed on the most recent run. */
    public static ?DateTimeImmutable $seenDeadline = null;

    /** The correlation and tenant ids the context exposed, per run. */
    public static ?string $seenCorrelationId = null;

    public static ?string $seenTenantId = null;

    /**
     * Runs inside handle(), before the step returns and is checkpointed.
     * Lets a test interfere with the world mid-step — for example by
     * letting another worker take the execution over.
     */
    public static ?Closure $beforeReturn = null;

    /** Replaces the step's output when set, for payload-size tests. */
    public static mixed $output = null;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        static::$seenDeadline = $context->deadline();
        static::$seenCorrelationId = $context->correlationId();
        static::$seenTenantId = $context->tenantId();

        $context->set('one', 'from-step-one');

        if (static::$beforeReturn !== null) {
            (static::$beforeReturn)();
        }

        return static::$output ?? ['n' => 1];
    }
}
