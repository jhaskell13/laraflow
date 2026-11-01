<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Closure;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;
use Throwable;

/**
 * A step whose side effect is a counter, remembered under a key, with
 * hooks for everything the idempotency contract has to prove: the
 * work throwing, the step failing after the work, and a reconciler.
 */
class RememberingStep implements Step
{
    public static int $runs = 0;

    /** How many times the remembered work actually ran — the side effect. */
    public static int $effects = 0;

    public static string $key = 'charge';

    /** The work's return value; counts are appended so each effect is distinguishable. */
    public static mixed $value = 'CH-1';

    /** Thrown by the work on its next run, then cleared. */
    public static ?Throwable $workThrows = null;

    /** Thrown by the step *after* the work settled — the point of the store. */
    public static int $failAfterWorkTimes = 0;

    /** The reconciler handed to remember(), if any. */
    public static ?Closure $reconcile = null;

    /** Runs between the marker and the value: where a worker dies. */
    public static ?Closure $insideWork = null;

    /** What the step got back from remember() on its last run. */
    public static mixed $remembered = null;

    /**
     * Further keys remembered after the main one, each with a trivial
     * value — for the per-execution cap.
     *
     * @var list<string>
     */
    public static array $extraKeys = [];

    public static function reset(): void
    {
        static::$runs = 0;
        static::$effects = 0;
        static::$key = 'charge';
        static::$value = 'CH-1';
        static::$workThrows = null;
        static::$failAfterWorkTimes = 0;
        static::$reconcile = null;
        static::$insideWork = null;
        static::$remembered = null;
        static::$extraKeys = [];
    }

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;

        static::$remembered = $context->remember(static::$key, function (): mixed {
            if (static::$workThrows !== null) {
                $throw = static::$workThrows;
                static::$workThrows = null;

                throw $throw;
            }

            static::$effects++;

            if (static::$insideWork !== null) {
                (static::$insideWork)();
            }

            return static::$value;
        }, static::$reconcile);

        foreach (static::$extraKeys as $extra) {
            $context->remember($extra, fn (): string => "value-of-{$extra}");
        }

        if (static::$failAfterWorkTimes > 0) {
            static::$failAfterWorkTimes--;

            throw new RuntimeException('failed after the work was done');
        }

        $context->set('remembered', static::$remembered);

        return static::$remembered;
    }
}
