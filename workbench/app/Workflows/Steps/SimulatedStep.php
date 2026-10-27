<?php

declare(strict_types=1);

namespace Workbench\App\Workflows\Steps;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * Shared behaviour for the playground's steps: do some work that takes
 * real time, then fail if this execution's input says this position
 * should.
 *
 * The deadline handling is the interesting part. By default a step
 * bounds its own work by `$context->remainingSeconds()`, which is the
 * pattern the documentation asks for and means it never overruns no
 * matter how long it was told to work. With `hang` set it ignores the
 * deadline instead, so the playground can show what an overdue step
 * looks like and how recovery classifies it.
 */
abstract class SimulatedStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $workedMs = $this->work($context);

        if ($this->shouldFail($context)) {
            throw new RuntimeException(sprintf(
                'Simulated failure in %s on attempt %d.',
                class_basename(static::class),
                $context->attempt(),
            ));
        }

        return [
            'step' => class_basename(static::class),
            'worked_ms' => $workedMs,
            'attempt' => $context->attempt(),
            'idempotency_key' => $context->idempotencyKey(),
        ];
    }

    /**
     * Sleep for the configured duration, and return how long that
     * actually was.
     */
    protected function work(WorkflowContext $context): int
    {
        $requested = max(0, (int) $context->get('work_ms', 250));

        if (! $this->isHanging($context)) {
            $remaining = $context->remainingSeconds();

            if ($remaining !== null) {
                // Leave a little of the budget unspent; a step that
                // finishes exactly on its deadline is a step that
                // sometimes finishes after it.
                $budget = (int) max(0, $remaining * 1000 - 100);
                $requested = min($requested, $budget);
            }
        }

        usleep($requested * 1000);

        return $requested;
    }

    protected function shouldFail(WorkflowContext $context): bool
    {
        if ($context->get('fail_at') !== $context->stepPosition()) {
            return false;
        }

        // A step told to hang is demonstrating its deadline, not a
        // thrown failure; letting it also throw would conflate the two.
        if ($this->isHanging($context)) {
            return false;
        }

        return $context->get('fail_mode', 'once') === 'always'
            || $context->attempt() === 1;
    }

    protected function isHanging(WorkflowContext $context): bool
    {
        return $context->get('hang', false) === true
            && $context->get('fail_at') === $context->stepPosition();
    }
}
