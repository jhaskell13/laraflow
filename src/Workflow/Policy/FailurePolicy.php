<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Policy;

/**
 * What the runner does with the remaining steps once a step has
 * exhausted its retries. Declared per workflow via
 * Workflow::failurePolicy().
 */
enum FailurePolicy: string
{
    /**
     * Default. The execution stops at the failed step; later steps stay
     * pending and run only when a re-execution gets past it.
     */
    case Stop = 'stop';

    /**
     * Remaining steps still run after a step fails terminally.
     *
     * For workflows of independent steps only: later steps see earlier
     * checkpoints but nothing from the failed step. The execution still
     * ends `failed`, and execute() rethrows the first step's exception
     * once the remaining steps have run.
     */
    case Continue = 'continue';
}
