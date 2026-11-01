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
 * This class proposes; StepResolver::assertMatches() judges. A
 * migration whose output is not the workflow's current step list fails
 * the ordinary plan check afterwards, naming both lists.
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
     * @throws PlanMigrationRefusedException when no declared path exists, or one reaches into the record.
     */
    public function migrate(
        WorkflowExecution $execution,
        Workflow $workflow,
        array $stepExecutions,
    ): array {
        $from = $execution->planVersion;
        $to = $workflow->version();

        if ($from === $to) {
            return $stepExecutions;
        }

        // A record ahead of the code is a rollback, not a migration.
        // Carrying it backwards would mean inventing a plan no author
        // declared, so it joins every other way durable state and code
        // can disagree: refused, by the plan check below.
        if ($from > $to) {
            return $stepExecutions;
        }

        $migrations = $workflow->planMigrations();
        $draft = PlanDraft::fromSteps($workflow::class, $stepExecutions);

        for ($version = $from; $version < $to; $version++) {
            $this->hop($workflow, $migrations, $version, $to)($draft);
        }

        $tail = array_slice($draft->steps(), $draft->frozenBefore());

        // The migration's output is validated exactly as a plan is at
        // start(): non-empty, every entry an existing Step class. An
        // author's array is user input wherever it arrives from.
        $this->steps->validatePlan($workflow::class, $draft->steps());

        $migrated = $this->repository->rewritePlan($execution, $draft->frozenBefore(), $tail, $to);

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
