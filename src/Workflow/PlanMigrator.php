<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Events\WorkflowPlanMigrated;
use Laraflow\Exceptions\Workflow\PlanMigrationRefusedException;
use Psr\Log\LoggerInterface;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Carries an execution's persisted plan forward to the version its
 * workflow now declares, one declared hop at a time.
 *
 * Runs under the claim, before the first step write, so a migration
 * that throws leaves the execution exactly where a listener throwing
 * on WorkflowExecutionStarted leaves it: claimed, with no step write,
 * bounded by the fruitless-claims counter rather than by a mechanism
 * of its own. The rewrite itself is one claim-guarded transaction.
 *
 * This class proposes; the definition judges — and it judges *before*
 * anything is written. A migration whose output is not the workflow's
 * current step list is refused with nothing committed, so a wrong
 * closure can be fixed and the next claim tries again, rather than
 * leaving a plan no code describes and an event that announced it.
 *
 * Semantics: docs/workflow-versioning.md.
 */
final class PlanMigrator
{
    public function __construct(
        private ExecutionRepository $repository,
        private StepResolver $steps,
        private OutcomeRecorder $outcomes,
        private LoggerInterface $logger,
    ) {}

    /**
     * Migrate the plan if the workflow has moved on, returning the step
     * rows to run — the originals when nothing was due.
     *
     * @param  StepExecution[]  $stepExecutions  The persisted plan, in position order.
     * @return StepExecution[]
     *
     * @throws PlanMigrationRefusedException when no declared path exists, one reaches into the record,
     *                                       or the result is not the current definition.
     */
    public function migrate(
        WorkflowExecution $execution,
        Workflow $workflow,
        array $stepExecutions,
    ): array {
        $from = $execution->planVersion;
        $to = $workflow->version();

        // Equal: nothing to do. Ahead of the code — a rollback — is not
        // migrated backwards: that would mean inventing a plan no
        // author declared. The ordinary plan check that follows
        // refuses it unless the plan happens to be identical, in which
        // case there is nothing to refuse.
        if ($from >= $to) {
            return $stepExecutions;
        }

        $migrations = $workflow->planMigrations();
        $draft = PlanDraft::fromSteps($workflow::class, $stepExecutions);

        for ($version = $from; $version < $to; $version++) {
            $this->hop($workflow, $migrations, $version, $to)($draft);
        }

        // The judge, before the commit. The migration's output is held
        // to the same rules as a plan at start() — non-empty, every
        // entry an existing Step class — and then to the definition
        // itself. Either refusal writes nothing.
        $this->steps->validatePlan($workflow::class, $draft->steps());

        if ($draft->steps() !== $workflow->steps()) {
            throw PlanMigrationRefusedException::doesNotProduceDefinition($workflow::class, $draft->steps(), $workflow->steps());
        }

        $migrated = $this->repository->rewritePlan($execution, $draft->frozenBefore(), $draft->tail(), $to);

        $this->outcomes->emit(new WorkflowPlanMigrated(
            ...$this->outcomes->identity($execution),
            fromVersion: $from,
            toVersion: $to,
            steps: count($migrated),
        ));

        $this->logger->info('Laraflow migrated an execution to a new plan version.', [
            'execution_id' => $execution->id,
            'workflow' => $workflow::class,
            'from_version' => $from,
            'to_version' => $to,
            'operations' => $draft->operations(),
            'steps' => count($migrated),
        ]);

        return $migrated;
    }

    /**
     * One declared hop, validated.
     *
     * Separate so the array arrives typed as `mixed`: the return type
     * of planMigrations() is a contract for authors, not a guarantee,
     * and trusting it here would remove the checks this method exists
     * to make — the same reasoning StepResolver applies to steps().
     *
     * @param  array<array-key, mixed>  $migrations
     *
     * @throws PlanMigrationRefusedException
     */
    private function hop(Workflow $workflow, array $migrations, int $version, int $to): callable
    {
        $migration = $migrations[$version] ?? throw PlanMigrationRefusedException::missingMigration(
            $workflow::class,
            $version,
            $to,
        );

        if (! is_callable($migration)) {
            throw PlanMigrationRefusedException::notACallable($workflow::class, $version);
        }

        return $migration;
    }
}
