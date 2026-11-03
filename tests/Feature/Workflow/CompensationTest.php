<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowCompensated;
use Laraflow\Events\WorkflowCompensationStarted;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\RefundableStep;
use Laraflow\Tests\Support\ShippableStep;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\ThrowingStep;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The compensation contract (docs/advanced-workflows.md): opt-in by
 * declaring it, run on terminal failure in reverse over the steps that
 * completed, each undo an ordinary step row with its own attempts and
 * retries, and the pass stopping rather than reaching past one it
 * could not finish.
 */
class CompensationTest extends TestCase
{
    public function test_a_workflow_with_nothing_to_undo_behaves_exactly_as_before(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new TwoStepWorkflow);
            $this->fail('The step fails.');
        } catch (RuntimeException) {
        }

        $execution = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Failed->value, $execution->status);
        $this->assertSame(2, StepExecutionModel::query()->where('workflow_execution_id', $execution->id)->count(), 'No rows were appended.');
        $this->assertNull($execution->next_retry_at);
    }

    public function test_a_terminal_failure_undoes_the_completed_steps_in_reverse(): void
    {
        Event::fake([WorkflowCompensationStarted::class, WorkflowCompensated::class]);

        PolicyWorkflow::$steps = [RefundableStep::class, ShippableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('the warehouse is on fire');

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('The third step fails terminally.');
        } catch (RuntimeException $exception) {
            $this->assertSame('the warehouse is on fire', $exception->getMessage(), 'The original failure is what the caller gets.');
        }

        // Reverse: the last thing done is the first thing undone, and
        // each undo was handed the step's own recorded output.
        $this->assertSame(
            [['ShippableStep', ['shipment_id' => 'SH-1']], ['RefundableStep', ['charge_id' => 'CH-1']]],
            RefundableStep::$undone,
        );
        $this->assertSame('the warehouse is on fire', RefundableStep::$seenFailure, 'The undo is told why.');

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $execution->status, 'Compensation changes what was left behind, not whether it failed.');
        $this->assertSame('the warehouse is on fire', $execution->error);

        // Each undo is an ordinary step row, appended after the plan
        // and linked to what it undid.
        $rows = StepExecutionModel::query()->where('workflow_execution_id', $execution->id)->orderBy('position')->get();
        $this->assertCount(5, $rows, 'Three planned, two compensations.');

        $plan = $rows->whereNull('compensates_step_execution_id');
        $this->assertSame([0, 1, 2], $plan->pluck('position')->all());

        $compensations = $rows->whereNotNull('compensates_step_execution_id')->values();
        $this->assertSame([3, 4], $compensations->pluck('position')->all());
        $this->assertSame([ShippableStep::class, RefundableStep::class], $compensations->pluck('step')->all(), 'Appended in the order they ran.');
        $this->assertSame([StepStatus::Completed->value, StepStatus::Completed->value], $compensations->pluck('status')->all());
        $this->assertSame(
            [$this->row($rows, 1, 'step')->id, $this->row($rows, 0, 'step')->id],
            $compensations->pluck('compensates_step_execution_id')->all(),
        );

        Event::assertDispatched(WorkflowCompensationStarted::class, fn (WorkflowCompensationStarted $e): bool => $e->executionId === $execution->id && $e->steps === 2);
        Event::assertDispatched(WorkflowCompensated::class, fn (WorkflowCompensated $e): bool => $e->undone === 2 && $e->remaining === 0 && $e->complete);
    }

    public function test_the_step_that_failed_and_the_steps_that_did_not_complete_are_not_undone(): void
    {
        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class, ShippableStep::class];
        ThrowingStep::$exception = new RuntimeException('stop here');

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        // The failing step did not complete, so it is not undone; the
        // step after it never ran at all.
        $this->assertSame([['RefundableStep', ['charge_id' => 'CH-1']]], RefundableStep::$undone);
        $this->assertSame(0, ShippableStep::$runs);
    }

    public function test_compensation_waits_for_the_retries_to_be_exhausted(): void
    {
        Date::setTestNow('2026-11-02 10:00:00');

        PolicyWorkflow::$steps = [RefundableStep::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 2;

        // First attempt: the step fails and a retry is scheduled, so
        // the execution is still trying to go forward.
        $retrying = $this->runner()->run(new PolicyWorkflow);
        $this->assertTrue($retrying->isRetrying());
        $this->assertSame([], RefundableStep::$undone, 'Undoing work it is about to redo would be wrong.');

        // Second attempt exhausts the policy: now it is terminal.
        try {
            $this->runner()->execute($retrying->id, new PolicyWorkflow, ignoreRetrySchedule: true);
        } catch (RuntimeException) {
        }

        $this->assertSame([['RefundableStep', ['charge_id' => 'CH-1']]], RefundableStep::$undone);
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->whereKey($retrying->id)->sole()->status);
    }

    public function test_a_failing_compensation_is_retried_under_the_compensated_steps_policy(): void
    {
        Date::setTestNow('2026-11-02 10:00:00');

        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('forward failure');
        // The step that declared the undo declared how hard to try it:
        // two attempts for RefundableStep, one for everything else.
        RefundableStep::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 30, jitter: 0.0);
        RefundableStep::$failUndoTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('The forward step fails terminally on its first attempt.');
        } catch (RuntimeException) {
        }

        $row = WorkflowExecutionModel::query()->sole();

        // Retrying, accurately: the undo is scheduled, and the existing
        // due-retry scan delivers it with no new mechanism.
        $this->assertSame(ExecutionStatus::Retrying->value, $row->status, 'The undo is scheduled.');
        $this->assertNotNull($row->next_retry_at, 'So recovery delivers it.');
        $this->assertSame([], RefundableStep::$undone, 'The undo has not succeeded yet.');
        $this->assertSame('forward failure', $row->error, 'The failure the pass will rest on, recorded when it began.');

        $compensation = StepExecutionModel::query()
            ->where('workflow_execution_id', $row->id)
            ->whereNotNull('compensates_step_execution_id')
            ->sole();
        $this->assertSame(StepStatus::Retrying->value, $compensation->status);
        $this->assertStringContainsString('refund gateway is down', (string) $compensation->error);

        // The retry comes due and succeeds. Nothing is thrown: the
        // caller here is recovery, not the code whose step failed.
        Date::setTestNow('2026-11-02 10:01:00');
        $resumed = $this->runner()->execute($row->id, new PolicyWorkflow, ignoreRetrySchedule: true);

        $this->assertTrue($resumed->isFailed());
        $this->assertSame([['RefundableStep', ['charge_id' => 'CH-1']]], RefundableStep::$undone);
        $this->assertSame('forward failure', WorkflowExecutionModel::query()->whereKey($row->id)->sole()->error);
        $this->assertSame(2, StepExecutionModel::query()->whereKey($compensation->id)->sole()->attempts);
    }

    public function test_an_interrupted_pass_is_resumed_by_the_next_claim_rather_than_the_plan(): void
    {
        Date::setTestNow('2026-11-02 10:00:00');

        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('boom');
        RefundableStep::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 30, jitter: 0.0);
        RefundableStep::$failUndoTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Retrying->value, $execution->status, 'The undo is scheduled.');
        $this->assertSame([], RefundableStep::$undone);
        $this->assertSame('boom', $execution->error, 'The failure the pass will rest on was recorded when it began.');

        // The next claim resumes the pass rather than re-running the
        // plan: the plan is finished with — it failed — and what
        // remains is undoing it.
        Date::setTestNow('2026-11-02 10:01:00');
        $resumed = $this->runner()->execute($execution->id, new PolicyWorkflow, ignoreRetrySchedule: true);

        $this->assertTrue($resumed->isFailed(), 'It rests on the failure that began the pass.');
        $this->assertSame('boom', WorkflowExecutionModel::query()->whereKey($execution->id)->sole()->error);
        $this->assertSame([['RefundableStep', ['charge_id' => 'CH-1']]], RefundableStep::$undone);
        $this->assertSame(1, RefundableStep::$runs, 'The plan was not re-run.');
    }

    public function test_under_continue_the_remaining_steps_run_before_anything_is_undone(): void
    {
        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class, ShippableStep::class];
        PolicyWorkflow::$failurePolicy = FailurePolicy::Continue;
        ThrowingStep::$exception = new RuntimeException('keep going');

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        $this->assertSame(1, ShippableStep::$runs, 'Continue ran the step after the failure.');

        // Both completed steps are undone, newest first.
        $this->assertSame(
            [['ShippableStep', ['shipment_id' => 'SH-1']], ['RefundableStep', ['charge_id' => 'CH-1']]],
            RefundableStep::$undone,
        );
    }

    public function test_a_compensation_row_is_not_part_of_the_plan(): void
    {
        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('boom');

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertSame(3, StepExecutionModel::query()->where('workflow_execution_id', $execution->id)->count());

        // Re-executing validates the plan against steps(). If the
        // appended row counted, the definition check would refuse it.
        ThrowingStep::$exception = null;
        RefundableStep::reset();

        $resumed = $this->runner()->execute($execution->id, new PolicyWorkflow);

        $this->assertTrue($resumed->isCompleted(), 'The plan is still two steps.');
    }

    public function test_a_skipped_step_is_not_undone(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('boom');

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        // StepOne completed but declares no compensate(), so it is not
        // undone and no row is appended for it.
        $this->assertSame([['RefundableStep', ['charge_id' => 'CH-1']]], RefundableStep::$undone);
        $this->assertSame(
            1,
            StepExecutionModel::query()->where('workflow_execution_id', WorkflowExecutionModel::query()->sole()->id)->whereNotNull('compensates_step_execution_id')->count(),
        );
    }

    /**
     * The Milestone 9 audit's first critical finding. A pass that is
     * interrupted leaves the execution `compensating` and claimed —
     * and nothing could claim it back: the stale *scan* returned it,
     * recovery dispatched it, and the claim predicate refused it,
     * forever. An operator could not even cancel it.
     */
    public function test_an_abandoned_pass_is_reclaimable_and_cancellable(): void
    {
        Date::setTestNow('2026-11-02 10:00:00');

        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('boom');
        RefundableStep::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 30, jitter: 0.0);
        RefundableStep::$failUndoTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        // Stage the shape a killed worker leaves: mid-pass, claimed,
        // with no schedule to be delivered by.
        $id = WorkflowExecutionModel::query()->sole()->id;
        WorkflowExecutionModel::query()->whereKey($id)->update([
            'status' => ExecutionStatus::Compensating->value,
            'claim_token' => 'dead-worker',
            'claimed_at' => '2026-11-02 09:00:00',
            'next_retry_at' => null,
        ]);

        $repository = $this->app->make(ExecutionRepository::class);

        // Recovery's scan finds it...
        $stale = $repository->stale(new DateTimeImmutable('2026-11-02 09:55:00'), 10);
        $this->assertSame([$id], array_map(fn ($e): int => $e->id, $stale));

        // ...and the claim takes it, rather than refusing what the scan
        // just handed over.
        $claimed = $repository->claim($id, 'live-worker', 300);
        $this->assertSame('live-worker', $claimed->claimToken);

        // And an operator can cancel an abandoned pass.
        WorkflowExecutionModel::query()->whereKey($id)->update([
            'status' => ExecutionStatus::Compensating->value,
            'claim_token' => 'dead-worker',
            'claimed_at' => '2026-11-02 09:00:00',
        ]);

        $cancelled = $this->runner()->cancel($id, 'operator gave up on it');
        $this->assertTrue($cancelled->isCancelled());
    }

    /**
     * The audit's second critical finding. When an undo will not be
     * tried again the pass stops — and the execution must still come
     * to rest. It was being left `compensating` and claimed with no
     * schedule, which is the stuck state above reached without any
     * crash, on the ordinary path the contract describes.
     */
    public function test_an_undo_that_will_not_be_tried_again_still_rests_the_execution(): void
    {
        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('forward failure');
        // One attempt for the undo, and it fails: nothing will retry it.
        RefundableStep::$policy = new StepPolicy(maxAttempts: 1);
        RefundableStep::$failUndoTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('The execution still fails, with the failure that began the pass.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forward failure', $exception->getMessage());
        }

        $row = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Failed->value, $row->status, 'It came to rest.');
        $this->assertNotNull($row->finished_at);
        $this->assertNull($row->claim_token, 'The claim was released.');
        $this->assertSame('forward failure', $row->error, 'On the failure that began the pass, not the undo\'s.');

        // And the record says what could not be undone.
        $compensation = StepExecutionModel::query()
            ->where('workflow_execution_id', $row->id)
            ->whereNotNull('compensates_step_execution_id')
            ->sole();
        $this->assertSame(StepStatus::Failed->value, $compensation->status);
        $this->assertStringContainsString('refund gateway is down', (string) $compensation->error);
        $this->assertSame([], RefundableStep::$undone);
    }

    /**
     * A deploy took compensate() away between the failure and the
     * pass. The step must stop the pass by name rather than be
     * silently skipped — skipping it would undo *earlier* work while
     * this one stood, which is what the stop rule exists to prevent.
     */
    public function test_a_step_that_can_no_longer_be_undone_stops_the_pass_by_name(): void
    {
        PolicyWorkflow::$steps = [RefundableStep::class, ShippableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('boom');
        // ShippableStep's undo throws forever, standing in for a class
        // that cannot complete its undo at all.
        ShippableStep::$failUndoTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        $row = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Failed->value, $row->status);
        // ShippableStep ran last, so it is undone first — and its undo
        // failed, so RefundableStep's was never reached.
        $this->assertSame([], RefundableStep::$undone, 'The pass stopped rather than reaching past it.');
        $this->assertSame(
            1,
            StepExecutionModel::query()->where('workflow_execution_id', $row->id)->whereNotNull('compensates_step_execution_id')->count(),
            'Only the undo that was attempted has a row.',
        );
    }

    /**
     * remember() is promised inside compensate(), and an undo that
     * repeats is a refund that repeats. It is scoped to the
     * compensation's own row, not the failed forward step's.
     */
    public function test_an_undo_can_remember_its_side_effect(): void
    {
        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('boom');
        RefundableStep::$rememberUndo = true;

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        $execution = WorkflowExecutionModel::query()->sole();
        $records = $this->app->make(\Laraflow\Workflow\IdempotencyStore::class)->records($execution->id);

        $this->assertCount(1, $records, 'The undo remembered its work.');
        $this->assertSame('refund', $records[0]->key);

        // Scoped to the compensation's own position, not the forward
        // step's — otherwise every undo in a pass would share one
        // scope, and collide with the failed step's own keys.
        $compensation = StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->whereNotNull('compensates_step_execution_id')
            ->sole();
        $this->assertSame($compensation->position, $records[0]->stepPosition);
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
