<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\CompensatableStep;
use Laraflow\Workflow\Compensation;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * A second compensatable step, so a reverse pass has an order to get
 * right. Shares RefundableStep's ledger.
 */
class ShippableStep implements CompensatableStep, Step
{
    public static int $runs = 0;

    /** How many times compensate() should throw before succeeding. */
    public static int $failUndoTimes = 0;

    public static function reset(): void
    {
        static::$runs = 0;
        static::$failUndoTimes = 0;
    }

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        $context->set('shipped', true);

        return ['shipment_id' => 'SH-1'];
    }

    public function compensate(Compensation $compensation): void
    {
        if (static::$failUndoTimes > 0) {
            static::$failUndoTimes--;

            throw new RuntimeException('the carrier refused the cancellation');
        }

        RefundableStep::$undone[] = [class_basename($compensation->step), $compensation->output()];
    }
}
