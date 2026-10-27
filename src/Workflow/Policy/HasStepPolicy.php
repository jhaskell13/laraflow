<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Policy;

/**
 * Optional interface for steps that declare their own retry and timeout
 * policy, which takes precedence over the workflow's default and the
 * laraflow.retry / laraflow.timeout configuration.
 *
 * It is a separate interface rather than a method on Step so that
 * existing steps keep working: Step has one required method and gains
 * none.
 */
interface HasStepPolicy
{
    public function stepPolicy(): StepPolicy;
}
