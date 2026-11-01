<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A declared plan migration asked for something the record forbids, or
 * produced a plan the workflow does not describe.
 *
 * A migration may rewrite only the undecided tail of a plan — the
 * steps after the last one that has already been decided. Everything
 * before it is the record of what happened, and no deploy may rewrite
 * it. Thrown before any step runs, so no retry policy is consulted;
 * the same migration against the same record refuses identically.
 * See docs/workflow-versioning.md.
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

    public static function frozenByLaterStep(string $workflow, string $step, string $operation, string $decided, string $decidedStatus): self
    {
        return new self(sprintf(
            'The plan migration for [%s] tried to %s step [%s]. It is still pending, but it sits before [%s], '
            .'which is [%s]: the plan up to the last decided step is the record, and a migration may only change '
            .'the steps after it.',
            $workflow,
            $operation,
            $step,
            $decided,
            $decidedStatus,
        ));
    }

    public static function insertionInFrozenPrefix(string $workflow, string $step, string $decided, string $decidedStatus): self
    {
        return new self(sprintf(
            'The plan migration for [%s] tried to insert step [%s] before [%s], which is [%s] and therefore part of '
            .'the record. A new step may only be added after the last step this execution has already decided.',
            $workflow,
            $step,
            $decided,
            $decidedStatus,
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
            'Step [%s] would appear more than once in the plan for [%s], so a migration could not say which one it means. '
            .'Plan migrations address steps by class name; a plan that repeats a class cannot be migrated.',
            $step,
            $workflow,
        ));
    }

    public static function missingMigration(string $workflow, int $from, int $to): self
    {
        return new self(sprintf(
            'Execution is on plan version %d and [%s] declares version %d, but there is no plan migration from '
            .'version %d to %d. Declare one in planMigrations(), or the execution cannot be carried forward.',
            $from,
            $workflow,
            $to,
            $from,
            $from + 1,
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

    /**
     * @param  list<string>  $migrated
     * @param  list<string>  $defined
     */
    public static function doesNotProduceDefinition(string $workflow, array $migrated, array $defined): self
    {
        return new self(sprintf(
            'The plan migrations for [%s] produced [%s], but the workflow defines [%s]. Nothing was written: '
            .'a migration proposes, the definition judges, and this proposal is not the definition.',
            $workflow,
            implode(', ', $migrated),
            implode(', ', $defined),
        ));
    }
}
