<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * How a fan-out's join treats a member that does not complete.
 *
 * One case ships. Waiting for every member is the less surprising rule
 * and never destroys work that was nearly done; cancelling the siblings
 * on a first failure is a destructive reaction to a partial failure
 * that races every member already mid-step, and nothing has asked for
 * it. The enum exists so that adding it later is additive rather than
 * a signature change (docs/advanced-workflows.md, "Partial failure").
 */
enum JoinPolicy: string
{
    /**
     * Let every member reach a resting outcome, then decide: all
     * completed, the step completes; otherwise it fails with a summary.
     */
    case WaitAll = 'wait_all';
}
