<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\RetentionPolicy;
use Laraflow\Workflow\RetentionService;
use Laraflow\Workflow\RetentionTier;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Clock\ClockInterface;

/**
 * The retention engine.
 *
 * Executions are built through the real repository write path and then
 * aged by rewriting `finished_at`, because every bound is measured in
 * days and a test cannot wait one.
 */
class RetentionServiceTest extends TestCase
{
    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-10-25 12:00:00');
        $this->app->instance(ClockInterface::class, $this->clock);
    }

    // ------------------------------------------------------------------
    // Nothing happens unless asked
    // ------------------------------------------------------------------

    public function test_an_unconfigured_policy_does_nothing(): void
    {
        $this->aged('completed', 365);

        $report = $this->service()->prune(new RetentionPolicy);

        $this->assertTrue($report->isEmpty());
        $this->assertSame(0, $report->scanned);
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_work_inside_every_bound_is_untouched(): void
    {
        $this->aged('completed', 5);

        $report = $this->service()->prune(new RetentionPolicy(
            scrubAfterDays: 30,
            pruneAttemptsAfterDays: 30,
            deleteAfterDays: 90,
        ));

        $this->assertTrue($report->isEmpty());
        $this->assertNotNull(WorkflowExecutionModel::query()->sole()->input);
    }

    // ------------------------------------------------------------------
    // Unfinished work is invisible
    // ------------------------------------------------------------------

    public function test_unfinished_executions_are_never_eligible(): void
    {
        // Each is old by creation, but none has finished: finished_at is
        // the only clock retention reads.
        foreach (['pending', 'running', 'retrying'] as $state) {
            $this->unfinished($state);
        }

        $report = $this->service()->prune(new RetentionPolicy(
            scrubAfterDays: 1,
            pruneAttemptsAfterDays: 1,
            deleteAfterDays: 1,
            deleteFailedAfterDays: 1,
        ));

        $this->assertSame(0, $report->scanned);
        $this->assertSame(3, WorkflowExecutionModel::query()->count());
    }

    public function test_a_retry_waiting_out_its_backoff_is_not_old_work(): void
    {
        $execution = $this->unfinished('retrying');

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'next_retry_at' => '2026-10-25 12:05:00',
        ]);

        $this->service()->prune(new RetentionPolicy(deleteAfterDays: 1, deleteFailedAfterDays: 1));

        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_it_deletes_completed_and_cancelled_work_past_the_bound(): void
    {
        $completed = $this->aged('completed', 100);
        $cancelled = $this->aged('cancelled', 100);
        $recent = $this->aged('completed', 10);

        $report = $this->service()->prune(new RetentionPolicy(deleteAfterDays: 90));

        $this->assertSame(2, $report->deleted);
        $this->assertSame(0, $report->deletedFailed);
        $survivors = WorkflowExecutionModel::query()->pluck('id')->all();

        $this->assertSame([$recent->id], $survivors);
        $this->assertNotContains($completed->id, $survivors);
        $this->assertNotContains($cancelled->id, $survivors);
    }

    public function test_failed_work_is_not_deleted_by_the_general_bound(): void
    {
        $this->aged('failed', 365);

        $report = $this->service()->prune(new RetentionPolicy(deleteAfterDays: 1));

        // A failed execution is re-executable. Discarding one must be a
        // decision an operator took, not a side effect of configuring
        // general retention.
        $this->assertSame(0, $report->deleted);
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_failed_work_is_deleted_only_by_its_own_bound(): void
    {
        $this->aged('failed', 365);
        $this->aged('completed', 365);

        $report = $this->service()->prune(new RetentionPolicy(deleteFailedAfterDays: 180));

        $this->assertSame(1, $report->deletedFailed);
        $this->assertSame(0, $report->deleted, 'The general bound was not set.');
        $this->assertSame(
            [ExecutionStatus::Completed->value],
            WorkflowExecutionModel::query()->pluck('status')->all(),
        );
    }

    public function test_deleting_leaves_no_orphaned_steps_or_attempts(): void
    {
        $doomed = $this->aged('completed', 100, attempts: 2);
        $kept = $this->aged('completed', 10, attempts: 2);

        $this->service()->prune(new RetentionPolicy(deleteAfterDays: 90));

        $this->assertSame(0, StepExecutionModel::query()->where('workflow_execution_id', $doomed->id)->count());
        $this->assertSame(0, StepAttemptModel::query()->where('workflow_execution_id', $doomed->id)->count());

        // The survivor keeps everything.
        $this->assertSame(1, StepExecutionModel::query()->where('workflow_execution_id', $kept->id)->count());
        $this->assertSame(2, StepAttemptModel::query()->where('workflow_execution_id', $kept->id)->count());
    }

    public function test_deleting_leaves_no_orphans_without_database_cascades(): void
    {
        // SQLite enforces the declared cascade only when the connection
        // asks. Retention must clean up either way, so this proves the
        // behaviour with enforcement off.
        DB::statement('PRAGMA foreign_keys = OFF');

        $doomed = $this->aged('completed', 100, attempts: 2);

        $this->service()->prune(new RetentionPolicy(deleteAfterDays: 90));

        $this->assertNull(WorkflowExecutionModel::query()->find($doomed->id));
        $this->assertSame(0, StepExecutionModel::query()->count());
        $this->assertSame(0, StepAttemptModel::query()->count());
    }

    // ------------------------------------------------------------------
    // Prune attempts
    // ------------------------------------------------------------------

    public function test_it_prunes_attempt_history_and_keeps_the_execution(): void
    {
        $execution = $this->aged('completed', 30, attempts: 3);

        $report = $this->service()->prune(new RetentionPolicy(pruneAttemptsAfterDays: 7));

        $this->assertSame(1, $report->attemptExecutions);
        $this->assertSame(3, $report->attemptRows);
        $this->assertSame(0, StepAttemptModel::query()->count());

        // The record of what ran survives, including the step row's own
        // attempt counter, which is what the retry budget reads.
        $this->assertNotNull(WorkflowExecutionModel::query()->find($execution->id));
        $this->assertSame(3, StepExecutionModel::query()->sole()->attempts);
    }

    public function test_attempt_pruning_covers_failed_executions(): void
    {
        $this->aged('failed', 30, attempts: 2);

        $report = $this->service()->prune(new RetentionPolicy(pruneAttemptsAfterDays: 7));

        // Diagnostics for old attempts are lost; resumability is not,
        // because the step row keeps its status, error, and counter.
        $this->assertSame(2, $report->attemptRows);
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
        $this->assertNotNull(StepExecutionModel::query()->sole()->error);
    }

    public function test_pruning_attempts_leaves_the_payloads_alone(): void
    {
        $this->aged('completed', 30, attempts: 1);

        $this->service()->prune(new RetentionPolicy(pruneAttemptsAfterDays: 7));

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertNotNull($row->input);
        $this->assertNull($row->payloads_scrubbed_at);
    }

    // ------------------------------------------------------------------
    // Scrub
    // ------------------------------------------------------------------

    public function test_it_scrubs_payloads_and_keeps_the_audit_trail(): void
    {
        $this->aged('completed', 60, attempts: 1);

        $report = $this->service()->prune(new RetentionPolicy(scrubAfterDays: 30));

        $this->assertSame(1, $report->scrubbed);

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertNull($row->input);
        $this->assertNull($row->context);
        $this->assertSame('2026-10-25 12:00:00', $row->payloads_scrubbed_at?->format('Y-m-d H:i:s'));

        // What ran, when, and how it ended all survive.
        $this->assertSame(ExecutionStatus::Completed->value, $row->status);
        $this->assertNotNull($row->finished_at);

        $step = StepExecutionModel::query()->sole();
        $this->assertNull($step->output);
        $this->assertNotNull($step->completed_at);

        // Attempt history is a different tier and was not configured.
        $this->assertSame(1, StepAttemptModel::query()->count());
    }

    public function test_failed_executions_are_never_scrubbed(): void
    {
        $this->aged('failed', 365);

        $report = $this->service()->prune(new RetentionPolicy(scrubAfterDays: 1));

        // Clearing a failed execution's input would leave a record that
        // still advertises itself as re-executable and no longer is.
        $this->assertSame(0, $report->scrubbed);
        $this->assertNotNull(WorkflowExecutionModel::query()->sole()->input);
    }

    public function test_cancelled_executions_are_scrubbed(): void
    {
        $this->aged('cancelled', 60);

        $report = $this->service()->prune(new RetentionPolicy(scrubAfterDays: 30));

        $this->assertSame(1, $report->scrubbed);
        $this->assertNull(WorkflowExecutionModel::query()->sole()->input);
    }

    public function test_an_already_scrubbed_execution_is_not_scrubbed_again(): void
    {
        $this->aged('completed', 60);

        $first = $this->service()->prune(new RetentionPolicy(scrubAfterDays: 30));
        $second = $this->service()->prune(new RetentionPolicy(scrubAfterDays: 30));

        $this->assertSame(1, $first->scrubbed);

        // Self-limiting: without this a scheduled run would spend its
        // whole limit rescanning work it had already done and never
        // reach anything newer.
        $this->assertSame(0, $second->scrubbed);
        $this->assertSame(0, $second->scanned);
    }

    public function test_pruned_attempts_are_not_rescanned(): void
    {
        $this->aged('completed', 30, attempts: 2);

        $first = $this->service()->prune(new RetentionPolicy(pruneAttemptsAfterDays: 7));
        $second = $this->service()->prune(new RetentionPolicy(pruneAttemptsAfterDays: 7));

        $this->assertSame(2, $first->attemptRows);
        $this->assertSame(0, $second->scanned, 'An execution with no attempt rows left stops matching.');
    }

    // ------------------------------------------------------------------
    // The guard
    // ------------------------------------------------------------------

    public function test_an_execution_reclaimed_between_scan_and_write_is_spared(): void
    {
        $doomed = $this->aged('failed', 365, attempts: 1);
        $other = $this->aged('failed', 365, attempts: 1);

        $repository = $this->repository();
        $policy = new RetentionPolicy(deleteFailedAfterDays: 180);
        $cutoff = $policy->cutoff(RetentionTier::Delete, $this->clock->now(), failed: true);
        $this->assertNotNull($cutoff);

        $candidates = $repository->retentionCandidates(
            RetentionTier::Delete,
            [ExecutionStatus::Failed],
            $cutoff,
            100,
        );
        $this->assertCount(2, $candidates);

        // A worker claims one between the scan and the write: claiming
        // sets status = running and clears finished_at.
        $repository->claim($doomed->id, 'worker-a', 300);

        $deleted = $repository->deleteExecutions(
            [$doomed->id, $other->id],
            [ExecutionStatus::Failed],
            $cutoff,
        );

        $this->assertSame(1, $deleted, 'Only the unclaimed execution was deleted.');

        $survivors = WorkflowExecutionModel::query()->pluck('id')->all();
        $this->assertSame([$doomed->id], $survivors);

        // And the spared execution keeps its children, which the
        // orphan sweep must not have taken.
        $this->assertSame(1, StepExecutionModel::query()->where('workflow_execution_id', $doomed->id)->count());
        $this->assertSame(1, StepAttemptModel::query()->where('workflow_execution_id', $doomed->id)->count());
    }

    public function test_the_guard_protects_scrubbing_too(): void
    {
        $execution = $this->aged('completed', 60);
        $repository = $this->repository();
        $cutoff = new DateTimeImmutable('2026-09-25 12:00:00');

        // Status no longer matches what the scan saw.
        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'status' => ExecutionStatus::Running->value,
            'finished_at' => null,
        ]);

        $scrubbed = $repository->scrubPayloads(
            [$execution->id],
            [ExecutionStatus::Completed, ExecutionStatus::Cancelled],
            $cutoff,
            $this->clock->now(),
        );

        $this->assertSame(0, $scrubbed);
        $this->assertNotNull(WorkflowExecutionModel::query()->sole()->input);
    }

    public function test_the_guard_protects_attempt_pruning_too(): void
    {
        $execution = $this->aged('completed', 30, attempts: 2);
        $repository = $this->repository();

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'status' => ExecutionStatus::Running->value,
            'finished_at' => null,
        ]);

        $pruned = $repository->pruneAttempts(
            [$execution->id],
            [ExecutionStatus::Completed, ExecutionStatus::Cancelled, ExecutionStatus::Failed],
            new DateTimeImmutable('2026-10-18 12:00:00'),
        );

        $this->assertSame(['executions' => 0, 'rows' => 0], $pruned);
        $this->assertSame(2, StepAttemptModel::query()->count());
    }

    public function test_skipped_executions_are_reported(): void
    {
        $spared = $this->aged('completed', 100);
        $this->aged('completed', 100);

        $repository = $this->repository();
        $cutoff = new DateTimeImmutable('2026-07-27 12:00:00');

        $ids = $repository->retentionCandidates(
            RetentionTier::Delete,
            [ExecutionStatus::Completed],
            $cutoff,
            100,
        );
        $this->assertCount(2, $ids);

        WorkflowExecutionModel::query()->whereKey($spared->id)->update(['finished_at' => null]);

        $deleted = $repository->deleteExecutions(
            array_values(array_map(fn (WorkflowExecution $e) => $e->id, $ids)),
            [ExecutionStatus::Completed],
            $cutoff,
        );

        $this->assertSame(1, $deleted);
    }

    // ------------------------------------------------------------------
    // Ordering, bounds, and scoping
    // ------------------------------------------------------------------

    public function test_the_oldest_work_is_handled_first(): void
    {
        $oldest = $this->aged('completed', 300);
        $middle = $this->aged('completed', 200);
        $this->aged('completed', 100);

        $report = $this->service()->prune(new RetentionPolicy(deleteAfterDays: 90, limit: 2));

        $this->assertSame(2, $report->deleted);

        // A run that hits its limit has still made progress at the end
        // of history that matters.
        $remaining = WorkflowExecutionModel::query()->pluck('id')->all();
        $this->assertNotContains($oldest->id, $remaining);
        $this->assertNotContains($middle->id, $remaining);
        $this->assertCount(1, $remaining);
    }

    public function test_the_limit_bounds_each_run(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->aged('completed', 100);
        }

        $report = $this->service()->prune(new RetentionPolicy(deleteAfterDays: 90, limit: 3));

        $this->assertSame(3, $report->deleted);
        $this->assertSame(2, WorkflowExecutionModel::query()->count());
    }

    public function test_work_is_written_in_chunks(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->aged('completed', 100);
        }

        $report = $this->service()->prune(new RetentionPolicy(deleteAfterDays: 90, chunk: 2));

        // Five executions across chunks of two: the totals must still
        // add up to one complete pass.
        $this->assertSame(5, $report->deleted);
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_it_can_be_scoped_to_one_workflow(): void
    {
        $this->aged('completed', 100, workflow: 'App\Sync');
        $this->aged('completed', 100, workflow: 'App\Other');

        $report = $this->service()->prune(
            new RetentionPolicy(deleteAfterDays: 90),
            workflow: 'App\Sync',
        );

        $this->assertSame(1, $report->deleted);
        $this->assertSame(
            ['App\Other'],
            WorkflowExecutionModel::query()->pluck('workflow')->all(),
        );
    }

    public function test_it_can_be_scoped_to_one_tenant(): void
    {
        $this->aged('completed', 100, tenantId: 'acme');
        $this->aged('completed', 100, tenantId: 'globex');

        $report = $this->service()->prune(
            new RetentionPolicy(deleteAfterDays: 90),
            tenantId: 'acme',
        );

        $this->assertSame(1, $report->deleted);
        $this->assertSame(
            ['globex'],
            WorkflowExecutionModel::query()->pluck('tenant_id')->all(),
        );
    }

    // ------------------------------------------------------------------
    // Tier interaction
    // ------------------------------------------------------------------

    public function test_deleting_runs_before_the_gentler_tiers(): void
    {
        $ancient = $this->aged('completed', 300, attempts: 2);
        $middle = $this->aged('completed', 60, attempts: 2);
        $recent = $this->aged('completed', 10, attempts: 2);

        $report = $this->service()->prune(new RetentionPolicy(
            scrubAfterDays: 30,
            pruneAttemptsAfterDays: 20,
            deleteAfterDays: 90,
        ));

        // The ancient one is gone, so the later tiers never did work on
        // a row that was about to disappear.
        $this->assertSame(1, $report->deleted);
        $this->assertSame(1, $report->scrubbed);
        $this->assertSame(1, $report->attemptExecutions);

        $this->assertNull(WorkflowExecutionModel::query()->find($ancient->id));

        $middleRow = WorkflowExecutionModel::query()->findOrFail($middle->id);
        $this->assertNull($middleRow->input, 'Past the scrub bound.');
        $this->assertSame(0, StepAttemptModel::query()->where('workflow_execution_id', $middle->id)->count());

        $recentRow = WorkflowExecutionModel::query()->findOrFail($recent->id);
        $this->assertNotNull($recentRow->input, 'Inside every bound.');
        $this->assertSame(2, StepAttemptModel::query()->where('workflow_execution_id', $recent->id)->count());
    }

    // ------------------------------------------------------------------
    // Dry run
    // ------------------------------------------------------------------

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->aged('completed', 300, attempts: 2);
        $this->aged('completed', 60, attempts: 2);
        $this->aged('failed', 300, attempts: 1);

        $report = $this->service()->prune(
            new RetentionPolicy(
                scrubAfterDays: 30,
                pruneAttemptsAfterDays: 20,
                deleteAfterDays: 90,
                deleteFailedAfterDays: 90,
            ),
            dryRun: true,
        );

        $this->assertTrue($report->dryRun);
        $this->assertSame(1, $report->deleted);
        $this->assertSame(1, $report->deletedFailed);
        $this->assertSame(1, $report->scrubbed);

        // Not a single write.
        $this->assertSame(3, WorkflowExecutionModel::query()->count());
        $this->assertSame(5, StepAttemptModel::query()->count());
        $this->assertNull(WorkflowExecutionModel::query()->first()?->payloads_scrubbed_at);
    }

    public function test_a_dry_run_predicts_the_run_that_follows_it(): void
    {
        $this->aged('completed', 300, attempts: 2);
        $this->aged('completed', 60, attempts: 2);
        $this->aged('failed', 300, attempts: 1);

        $policy = new RetentionPolicy(
            scrubAfterDays: 30,
            pruneAttemptsAfterDays: 20,
            deleteAfterDays: 90,
            deleteFailedAfterDays: 90,
        );

        $predicted = $this->service()->prune($policy, dryRun: true);
        $actual = $this->service()->prune($policy);

        // The whole value of a dry run is that its numbers are the ones
        // the real run produces. A dry run deletes nothing, so without
        // carrying the would-be-deleted ids forward the gentler tiers
        // would count work on rows that are about to disappear.
        $this->assertSame($predicted->deleted, $actual->deleted);
        $this->assertSame($predicted->deletedFailed, $actual->deletedFailed);
        $this->assertSame($predicted->scrubbed, $actual->scrubbed);
        $this->assertSame($predicted->attemptExecutions, $actual->attemptExecutions);
    }

    public function test_a_dry_run_reports_no_attempt_rows(): void
    {
        $this->aged('completed', 30, attempts: 3);

        $report = $this->service()->prune(
            new RetentionPolicy(pruneAttemptsAfterDays: 7),
            dryRun: true,
        );

        $this->assertSame(1, $report->attemptExecutions);

        // Counting rows would mean reading history this run is not
        // touching; an estimate an operator might act on is worse than
        // an honest absence.
        $this->assertSame(0, $report->attemptRows);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A finished execution whose finish is $days in the past.
     */
    protected function aged(
        string $outcome,
        int $days,
        int $attempts = 1,
        string $workflow = 'App\Sync',
        ?string $tenantId = null,
    ): WorkflowExecution {
        $repository = $this->repository();

        $execution = $repository->create(
            $workflow,
            input: ['customer_id' => 42, 'secret' => 'tok_SENTINEL'],
            steps: ['App\Steps\First'],
            options: new ExecutionOptions(tenantId: $tenantId),
        );

        $execution = $repository->claim($execution->id, 'worker', 300);
        $step = $repository->steps($execution)[0];

        $finishedAt = $this->clock->now()->modify("-{$days} days");

        // Every attempt but the last fails; the last one ends the step
        // the way the execution ends, so the step row and the attempt
        // history agree the way the runner would have left them.
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $attempt === $attempts;
            $succeeded = $last && $outcome !== 'failed';

            $step->markRunning($finishedAt);
            $repository->saveStep($step, new StepAttempt(
                executionId: $execution->id,
                stepExecutionId: $step->id,
                attempt: $attempt,
                status: $succeeded ? StepAttemptStatus::Completed : StepAttemptStatus::Failed,
                error: $succeeded ? null : 'boom',
                errorClass: $succeeded ? null : 'RuntimeException',
                startedAt: $finishedAt,
                endedAt: $finishedAt,
            ));

            if ($succeeded) {
                // Leaves an output for scrubbing to clear.
                $step->markCompleted(['receipt' => 'R-1'], $finishedAt);
            } else {
                $step->markFailed('boom', 'RuntimeException', $finishedAt);
            }

            $repository->saveStep($step);
        }

        $execution->context = ['loaded' => true];

        match ($outcome) {
            'completed' => $execution->markCompleted($finishedAt),
            'cancelled' => $execution->markCancelled($finishedAt, 'operator request'),
            'failed' => $execution->markFailed('boom', 'RuntimeException', $finishedAt),
            default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
        };

        $repository->release($execution);

        return $repository->find($execution->id);
    }

    /**
     * An execution that is old by creation but has never finished.
     */
    protected function unfinished(string $state): WorkflowExecution
    {
        $repository = $this->repository();
        $execution = $repository->create('App\Sync', input: ['a' => 1], steps: ['App\Steps\First']);

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'status' => $state,
            'created_at' => $this->clock->now()->modify('-365 days'),
            'finished_at' => null,
        ]);

        return $repository->find($execution->id);
    }

    protected function service(): RetentionService
    {
        return $this->app->make(RetentionService::class);
    }

    protected function repository(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
