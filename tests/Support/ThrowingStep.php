<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use Throwable;

/**
 * Throws whatever a test hands it, so the runner's handling of a
 * particular exception type can be observed in isolation.
 */
class ThrowingStep implements Step
{
    public static ?Throwable $exception = null;

    public function handle(WorkflowContext $context): mixed
    {
        if (static::$exception !== null) {
            throw static::$exception;
        }

        return 'ok';
    }
}
