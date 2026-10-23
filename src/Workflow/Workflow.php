<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\StepPolicy;

abstract class Workflow
{
    /**
     * The ordered list of step classes that make up this workflow.
     *
     * @return list<class-string<Step>>
     */
    abstract public function steps(): array;

    /**
     * Retry and timeout policy for this workflow's steps that do not
     * declare one of their own (see Policy\HasStepPolicy). Null falls
     * back to the configured package default, which disables retries.
     */
    public function defaultStepPolicy(): ?StepPolicy
    {
        return null;
    }

    /**
     * Whether the execution stops at a terminally failed step (the
     * default) or runs the remaining steps anyway. See FailurePolicy.
     */
    public function failurePolicy(): FailurePolicy
    {
        return FailurePolicy::Stop;
    }
}
