<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\CompensatableStep;
use Laraflow\Workflow\Compensation;
use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * A step with a side effect and a way to undo it. The undo records
 * what it saw, so a test can assert on the order of a reverse pass and
 * on what compensate() was handed.
 */
class RefundableStep implements CompensatableStep, HasStepPolicy, Step
{
    /** Governs this step's own retries — the forward one and the undo. */
    public static ?StepPolicy $policy = null;

    public static int $runs = 0;

    /**
     * What each compensate() call saw, in order.
     *
     * @var list<array{0: string, 1: mixed}>
     */
    public static array $undone = [];

    /** How many times compensate() should throw before succeeding. */
    public static int $failUndoTimes = 0;

    /** The failure message the last compensate() was told. */
    public static ?string $seenFailure = null;

    /** Whether the undo wraps its work in remember(). */
    public static bool $rememberUndo = false;

    public static function reset(): void
    {
        static::$policy = null;
        static::$runs = 0;
        static::$undone = [];
        static::$failUndoTimes = 0;
        static::$seenFailure = null;
        static::$rememberUndo = false;
    }

    public function stepPolicy(): StepPolicy
    {
        return static::$policy ?? new StepPolicy(maxAttempts: 1);
    }

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;

        return ['charge_id' => 'CH-'.static::$runs];
    }

    public function compensate(Compensation $compensation): void
    {
        if (static::$failUndoTimes > 0) {
            static::$failUndoTimes--;

            throw new RuntimeException('the refund gateway is down');
        }

        if (static::$rememberUndo) {
            $compensation->context()->remember('refund', fn (): string => 'refunded');
        }

        static::$seenFailure = $compensation->failure();
        static::$undone[] = [class_basename($compensation->step), $compensation->output()];
    }
}
