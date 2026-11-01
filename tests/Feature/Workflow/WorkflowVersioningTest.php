<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowPlanMigrated;
use Laraflow\Exceptions\Workflow\PlanMigrationRefusedException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepThree;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\VersionedWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\PlanDraft;
use Laraflow\Workflow\RecoveryService;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The contract in docs/workflow-versioning.md, and the Milestone 8
 * exit criterion: an in-flight execution survives a workflow
 * definition change through the documented migration path.
 */
class WorkflowVersioningTest extends TestCase
{
    use RunsArtisanCommands;

    // ------------------------------------------------------------------
    // The exit criterion
    // ------------------------------------------------------------------

    public function test_an_in_flight_execution_survives_a_definition_change(): void
    {
        // Deployed at version 1, failed partway, so step two is the
        // undecided tail and step one is the record.
        StepTwo::$failTimes = 1;
        $execution = $this->startAndFail();

        $this->assertSame(1, $this->executionRow($execution)->plan_version);
        $this->assertSame(StepStatus::Completed->value, $this->stepAt($this->executionRow($execution), 0)->status);

        // The deploy: a third step is added after the second, and the
        // workflow says how to carry existing executions forward.
        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->insertAfter(StepTwo::class, StepThree::class),
        ];
        StepTwo::$failTimes = 0;

        $result = $this->runner()->execute($execution->id, new VersionedWorkflow);

        $this->assertTrue($result->isCompleted(), 'The execution did not survive the definition change.');
        $this->assertSame(2, $this->executionRow($execution)->plan_version);
        $this->assertSame(
            [StepOne::class, StepTwo::class, StepThree::class],
            $this->planOf($execution),
        );

        // Step one is the record and was not re-run; the new step did.
        $this->assertSame(1, StepOne::$runs, 'A migration must not re-run a completed step.');
        $this->assertSame(1, StepThree::$runs);
    }

    public function test_the_migrated_execution_keeps_the_context_the_record_earned(): void
    {
        StepTwo::$failTimes = 1;
        $execution = $this->startAndFail();

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->insertAfter(StepTwo::class, StepThree::class),
        ];
        StepTwo::$failTimes = 0;

        $this->runner()->execute($execution->id, new VersionedWorkflow);

        // The step added by the migration sees what the steps before it
        // contributed, exactly as a step planned from the start would.
        $this->assertSame('from-step-two', StepThree::$seenContextValueFromStepTwo);
    }

    // ------------------------------------------------------------------
    // The refusal is the default
    // ------------------------------------------------------------------

    public function test_a_changed_plan_without_a_version_bump_still_strands_the_execution(): void
    {
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];

        $this->expectException(WorkflowDefinitionMismatchException::class);

        $this->runner()->execute($execution->id, new VersionedWorkflow);
    }

    public function test_a_version_bump_with_no_declared_migration_is_refused_by_name(): void
    {
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 2;

        try {
            $this->runner()->execute($execution->id, new VersionedWorkflow);
            $this->fail('A missing hop must be refused.');
        } catch (PlanMigrationRefusedException $exception) {
            $this->assertStringContainsString('no plan migration from version 1', $exception->getMessage());
        }

        $this->assertSame(1, $this->executionRow($execution)->plan_version, 'A refused migration writes nothing.');
    }

    public function test_a_record_ahead_of_the_code_is_never_migrated_backwards(): void
    {
        VersionedWorkflow::$version = 3;
        $execution = $this->runner()->start(new VersionedWorkflow);

        // The rollback: older code, declaring an older version and a
        // different plan.
        VersionedWorkflow::$version = 1;
        VersionedWorkflow::$steps = [StepOne::class];

        $this->expectException(WorkflowDefinitionMismatchException::class);

        try {
            $this->runner()->execute($execution->id, new VersionedWorkflow);
        } finally {
            $this->assertSame(3, $this->executionRow($execution)->plan_version, 'A rollback must not rewrite the plan.');
        }
    }

    // ------------------------------------------------------------------
    // Only the undecided tail
    // ------------------------------------------------------------------

    public function test_a_migration_may_not_remove_a_step_that_already_ran(): void
    {
        StepTwo::$failTimes = 1;
        $execution = $this->startAndFail();

        VersionedWorkflow::$steps = [StepTwo::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->remove(StepOne::class),
        ];

        try {
            $this->runner()->execute($execution->id, new VersionedWorkflow);
            $this->fail('Removing a completed step must be refused.');
        } catch (PlanMigrationRefusedException $exception) {
            $this->assertStringContainsString('remove step', $exception->getMessage());
            $this->assertStringContainsString('completed', $exception->getMessage());
        }

        $this->assertSame([StepOne::class, StepTwo::class], $this->planOf($execution));
        $this->assertSame(1, $this->executionRow($execution)->plan_version);
    }

    public function test_a_migration_may_not_insert_before_a_step_that_already_ran(): void
    {
        StepTwo::$failTimes = 1;
        $execution = $this->startAndFail();

        VersionedWorkflow::$steps = [StepThree::class, StepOne::class, StepTwo::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->insertBefore(StepOne::class, StepThree::class),
        ];

        $this->expectException(PlanMigrationRefusedException::class);
        $this->expectExceptionMessageMatches('/already been decided/');

        $this->runner()->execute($execution->id, new VersionedWorkflow);
    }

    public function test_a_migration_may_rewrite_the_tail_freely_before_anything_runs(): void
    {
        // Nothing decided yet, so the whole plan is the tail.
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepThree::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => function (PlanDraft $plan): void {
                $plan->remove(StepOne::class);
                $plan->replace(StepTwo::class, StepThree::class);
            },
        ];

        $result = $this->runner()->execute($execution->id, new VersionedWorkflow);

        $this->assertTrue($result->isCompleted());
        $this->assertSame([StepThree::class], $this->planOf($execution));
        $this->assertSame(0, StepOne::$runs, 'The removed step never ran.');
        $this->assertSame(1, StepThree::$runs);
    }

    public function test_the_rewrite_renumbers_the_tail_without_colliding_with_itself(): void
    {
        // (execution, position) is unique, so a plan that both loses and
        // gains steps in the tail is where an in-place renumber would
        // collide halfway through.
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepThree::class, StepTwo::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => function (PlanDraft $plan): void {
                $plan->remove(StepOne::class);
                $plan->insertBefore(StepTwo::class, StepThree::class);
            },
        ];

        $this->runner()->execute($execution->id, new VersionedWorkflow);

        $rows = StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->orderBy('position')
            ->get();

        $this->assertSame([0, 1], $rows->pluck('position')->all(), 'Positions are contiguous from zero.');
        $this->assertSame([StepThree::class, StepTwo::class], $rows->pluck('step')->all());
    }

    // ------------------------------------------------------------------
    // Chaining, validation, and what the migration may not decide
    // ------------------------------------------------------------------

    public function test_several_versions_are_chained_one_hop_at_a_time(): void
    {
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 3;
        $hops = [];
        VersionedWorkflow::$migrations = [
            1 => function (PlanDraft $plan) use (&$hops): void {
                $hops[] = 1;
                $plan->append(StepThree::class);
            },
            2 => function (PlanDraft $plan) use (&$hops): void {
                $hops[] = 2;
                // A no-op hop still has to run: version 3's plan is the
                // product of every hop before it.
            },
        ];

        $this->runner()->execute($execution->id, new VersionedWorkflow);

        $this->assertSame([1, 2], $hops, 'Every hop runs, in order.');
        $this->assertSame(3, $this->executionRow($execution)->plan_version);
    }

    public function test_a_migration_that_does_not_produce_the_current_definition_fails_the_plan_check(): void
    {
        // The migration proposes; steps() judges.
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->append(StepOne::class),
        ];

        $this->expectException(WorkflowDefinitionMismatchException::class);

        $this->runner()->execute($execution->id, new VersionedWorkflow);
    }

    public function test_a_migration_referring_to_an_unknown_step_is_refused(): void
    {
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->remove(StepThree::class),
        ];

        $this->expectException(PlanMigrationRefusedException::class);
        $this->expectExceptionMessageMatches('/not in this execution\'s plan/');

        $this->runner()->execute($execution->id, new VersionedWorkflow);
    }

    public function test_a_migration_that_is_not_callable_is_refused(): void
    {
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$version = 2;
        // planMigrations() is author-written, so its declared type is a
        // contract rather than a guarantee.
        VersionedWorkflow::$migrations = [1 => 'not a callable'];   // @phpstan-ignore-line

        $this->expectException(PlanMigrationRefusedException::class);
        $this->expectExceptionMessageMatches('/is not callable/');

        $this->runner()->execute($execution->id, new VersionedWorkflow);
    }

    // ------------------------------------------------------------------
    // Bounds, events, and observability
    // ------------------------------------------------------------------

    public function test_a_faulting_migration_is_bounded_by_the_fruitless_claim_counter(): void
    {
        // A migration that throws leaves the execution claimed with no
        // step write — the position fruitless_claims already bounds, so
        // versioning needs no bound of its own.
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->remove(StepThree::class),
        ];

        // The refused migration leaves the claim standing, so each
        // retake needs the stale window behind it — which is exactly
        // how recovery reaches such an execution.
        $runner = $this->makeRunner(options: new RunnerOptions(staleAfterSeconds: 0));

        foreach ([1, 2, 3] as $claim) {
            try {
                $runner->execute($execution->id, new VersionedWorkflow);
            } catch (PlanMigrationRefusedException) {
                // Expected on every claim.
            }

            $this->assertSame(
                $claim,
                $this->executionRow($execution)->fruitless_claims,
                'A migration that throws must count against the bound, not reset it.',
            );
        }

        // And recovery abandons it at the bound rather than
        // re-dispatching it forever.
        $candidates = $this->app->make(RecoveryService::class)->scan(0, null, 100, 3);

        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]->abandoned, 'Three fruitless claims is the bound.');
    }

    public function test_a_successful_rewrite_does_not_clear_the_fruitless_claim_counter(): void
    {
        // A migration that commits and *then* fails the plan check has
        // still reached no step. If the rewrite cleared the counter,
        // this claim would look like progress and the bound would be
        // one claim further away than it should be.
        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->append(StepOne::class),
        ];

        try {
            $this->runner()->execute($execution->id, new VersionedWorkflow);
            $this->fail('The migrated plan is not the definition.');
        } catch (WorkflowDefinitionMismatchException) {
            // The judge refused what the migration proposed.
        }

        $row = $this->executionRow($execution);

        $this->assertSame(2, $row->plan_version, 'The rewrite committed before the plan check ran.');
        $this->assertSame(1, $row->fruitless_claims, 'Migrating is not reaching a step.');
    }

    public function test_the_migration_event_fires_after_the_commit_it_describes(): void
    {
        Event::fake([WorkflowPlanMigrated::class]);

        $execution = $this->runner()->start(new VersionedWorkflow);

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->append(StepThree::class),
        ];

        $this->runner()->execute($execution->id, new VersionedWorkflow);

        Event::assertDispatched(
            WorkflowPlanMigrated::class,
            fn (WorkflowPlanMigrated $event): bool => $event->executionId === $execution->id
                && $event->fromVersion === 1
                && $event->toVersion === 2
                && $event->steps === 3
                && $event->workflow === VersionedWorkflow::class,
        );
    }

    public function test_an_unversioned_workflow_records_version_one_and_migrates_nothing(): void
    {
        Event::fake([WorkflowPlanMigrated::class]);

        $execution = $this->runner()->run(new VersionedWorkflow);

        $this->assertSame(1, $this->executionRow($execution)->plan_version);
        Event::assertNotDispatched(WorkflowPlanMigrated::class);
    }

    public function test_the_show_command_names_the_plan_version_once_it_has_moved(): void
    {
        $execution = $this->runner()->start(new VersionedWorkflow);

        ['output' => $before] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);
        $this->assertStringNotContainsString('Plan version', $before, 'Version 1 is every execution; it is noise.');

        VersionedWorkflow::$steps = [StepOne::class, StepTwo::class, StepThree::class];
        VersionedWorkflow::$version = 2;
        VersionedWorkflow::$migrations = [
            1 => fn (PlanDraft $plan) => $plan->append(StepThree::class),
        ];
        $this->runner()->execute($execution->id, new VersionedWorkflow);

        ['output' => $after] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);
        $this->assertStringContainsString('Plan version', $after);
        $this->assertStringContainsString('2', $after);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function startAndFail(): WorkflowExecution
    {
        $execution = $this->runner()->start(new VersionedWorkflow);

        try {
            $this->runner()->execute($execution->id, new VersionedWorkflow);
        } catch (RuntimeException) {
            // The fixture's deliberate failure.
        }

        return $execution;
    }

    /**
     * @return list<string>
     */
    protected function planOf(WorkflowExecution $execution): array
    {
        return array_values(array_map(
            fn (StepExecution $step): string => $step->step,
            $this->app->make(ExecutionRepository::class)->steps($execution),
        ));
    }

    protected function executionRow(WorkflowExecution $execution): WorkflowExecutionModel
    {
        return WorkflowExecutionModel::query()->findOrFail($execution->id);
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
