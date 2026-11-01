<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A declared plan migration asked for something the record forbids.
 *
 * A migration may rewrite only the undecided tail of a plan — the
 * steps after the last one that has already been decided. Everything
 * before it is the record of what happened, and no deploy may rewrite
 * it. See docs/workflow-versioning.md.
 *
 * Never retried: the same migration against the same record refuses
 * identically, so a retry can only spend the budget.
 */
class PlanMigrationRefusedException extends WorkflowException
{
    public static function frozenStep(string $workflow, string $step, string $operation, string $status): self
    {
        return new self(sprintf(
            'The plan migration for [%s] tried to %s step [%s], which is [%s] and therefore part of the record. '
            .'A migration may only change steps that have not been decided yet.',
            $workflow,
            $operation,
            $step,
            $status,
        ));
    }

    public static function insertionInFrozenPrefix(string $workflow, string $step, string $before): self
    {
        return new self(sprintf(
            'The plan migration for [%s] tried to insert step [%s] before [%s], which has already been decided. '
            .'A new step may only be added after the last step this execution has already reached.',
            $workflow,
            $step,
            $before,
        ));
    }

    public static function unknownStep(string $workflow, string $step): self
    {
        return new self(sprintf(
            'The plan migration for [%s] referred to step [%s], which is not in this execution\'s plan.',
            $workflow,
            $step,
        ));
    }

    public static function duplicateStep(string $workflow, string $step): self
    {
        return new self(sprintf(
            'Step [%s] appears more than once in the plan for [%s], so a migration cannot say which one it means. '
            .'Plan migrations address steps by class name.',
            $step,
            $workflow,
        ));
    }

    public static function missingMigration(string $workflow, int $from, int $to): self
    {
        return new self(sprintf(
            'Execution is on plan version %d and [%s] declares version %d, but there is no plan migration from '
            .'version %d. Declare one in planMigrations(), or the execution cannot be carried forward.',
            $from,
            $workflow,
            $to,
            $from,
        ));
    }

    public static function notACallable(string $workflow, int $from): self
    {
        return new self(sprintf(
            'The plan migration [%s] declares from version %d is not callable. planMigrations() returns '
            .'an array of callables keyed by the version each one migrates from.',
            $workflow,
            $from,
        ));
    }
}
