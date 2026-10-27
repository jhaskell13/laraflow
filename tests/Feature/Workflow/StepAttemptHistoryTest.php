<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use RuntimeException;

/**
 * The attempt history contract: one append-only row per finished
 * attempt, committed in the same transaction as the step state change
 * it describes — so history can never disagree with the step row, not
 * even under claim loss.
 */
class StepAttemptHistoryTest extends TestCase
{
    public function test_a_step_write_and_its_attempt_row_commit_together(): void
    {
        [$repository, $execution, $step] = $this->claimedExecutionWithFirstStep();

        $step->markRunning(new DateTimeImmutable('2026-10-23 10:00:00'));
        $step->markFailed('boom', RuntimeException::class, new DateTimeImmutable('2026-10-23 10:00:02'));

        $repository->saveStep($step, new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $step->id,
            attempt: 1,
            status: StepAttemptStatus::Failed,
            error: 'boom',
            errorClass: RuntimeException::class,
            startedAt: new DateTimeImmutable('2026-10-23 10:00:00'),
            endedAt: new DateTimeImmutable('2026-10-23 10:00:02'),
        ));

        $row = StepAttemptModel::query()->sole();
        $this->assertSame(1, $row->attempt);
        $this->assertSame(StepAttemptStatus::Failed->value, $row->status);
        $this->assertSame('boom', $row->error);
        $this->assertSame(RuntimeException::class, $row->error_class);
        $this->assertSame(2000, $row->duration_ms);

        $this->assertSame(
            StepStatus::Failed->value,
            StepExecutionModel::query()->whereKey($step->id)->value('status'),
        );
    }

    public function test_save_step_without_an_attempt_writes_no_history(): void
    {
        [$repository, , $step] = $this->claimedExecutionWithFirstStep();

        // A `running` marker is not a finished attempt.
        $step->markRunning(new DateTimeImmutable);
        $repository->saveStep($step);

        $this->assertSame(0, StepAttemptModel::query()->count());
    }

    public function test_a_checkpoint_records_the_completed_attempt_atomically(): void
    {
        [$repository, $execution, $step] = $this->claimedExecutionWithFirstStep();

        $step->markRunning(new DateTimeImmutable('2026-10-23 10:00:00'));
        $step->markCompleted(['n' => 1], new DateTimeImmutable('2026-10-23 10:00:01'));
        $execution->context = ['one' => 'done'];

        $repository->checkpoint($execution, $step, new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $step->id,
            attempt: 1,
            status: StepAttemptStatus::Completed,
            startedAt: new DateTimeImmutable('2026-10-23 10:00:00'),
            endedAt: new DateTimeImmutable('2026-10-23 10:00:01'),
        ));

        $row = StepAttemptModel::query()->sole();
        $this->assertSame(StepAttemptStatus::Completed->value, $row->status);
        $this->assertSame(1000, $row->duration_ms);
        $this->assertNull($row->error);
    }

    public function test_history_reads_back_in_attempt_order_with_each_attempts_diagnostics(): void
    {
        [$repository, $execution, $step] = $this->claimedExecutionWithFirstStep();

        // Recorded out of order on purpose; attempts() must sort.
        foreach ([2, 1] as $attempt) {
            $repository->saveStep($step, new StepAttempt(
                executionId: $execution->id,
                stepExecutionId: $step->id,
                attempt: $attempt,
                status: StepAttemptStatus::Failed,
                error: "failure {$attempt}",
                errorClass: RuntimeException::class,
            ));
        }

        $history = $repository->attempts($step);

        $this->assertCount(2, $history);
        $this->assertSame([1, 2], array_map(fn (StepAttempt $a): int => $a->attempt, $history));
        $this->assertSame('failure 1', $history[0]->error);
        $this->assertSame('failure 2', $history[1]->error, 'Attempt 1 diagnostics survive attempt 2.');
    }

    public function test_the_same_attempt_number_cannot_be_recorded_twice(): void
    {
        [$repository, $execution, $step] = $this->claimedExecutionWithFirstStep();

        $record = fn () => $repository->saveStep($step, new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $step->id,
            attempt: 1,
            status: StepAttemptStatus::Failed,
        ));

        $record();

        // The unique (step_execution_id, attempt) index makes a duplicate
        // delivery unable to double-count an attempt.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $record();
    }

    public function test_a_checkpoint_that_loses_the_claim_rolls_back_the_attempt_row_too(): void
    {
        [$repository, $execution, $step] = $this->claimedExecutionWithFirstStep();

        $step->markRunning(new DateTimeImmutable);
        $step->markCompleted(['n' => 1], new DateTimeImmutable);

        // Another worker reclaimed the execution: our token is gone.
        WorkflowExecutionModel::query()->whereKey($execution->id)->update(['claim_token' => 'thief']);

        try {
            $repository->checkpoint($execution, $step, new StepAttempt(
                executionId: $execution->id,
                stepExecutionId: $step->id,
                attempt: 1,
                status: StepAttemptStatus::Completed,
            ));
            $this->fail('Expected ClaimLostException.');
        } catch (ClaimLostException) {
            // Nothing this worker wrote may survive.
        }

        $this->assertSame(0, StepAttemptModel::query()->count(), 'No history without the claim.');
        $this->assertSame(
            StepStatus::Pending->value,
            StepExecutionModel::query()->whereKey($step->id)->value('status'),
            'The step write must roll back together with the attempt row.',
        );
    }

    public function test_an_interrupted_attempt_has_no_duration(): void
    {
        $attempt = new StepAttempt(
            executionId: 1,
            stepExecutionId: 1,
            attempt: 1,
            status: StepAttemptStatus::Interrupted,
            startedAt: new DateTimeImmutable('2026-10-23 10:00:00'),
            endedAt: null,
        );

        $this->assertNull($attempt->durationMs(), 'A dead worker recorded no end time.');
    }

    public function test_deleting_an_execution_cascades_to_its_attempt_history(): void
    {
        [$repository, $execution, $step] = $this->claimedExecutionWithFirstStep();

        $repository->saveStep($step, new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $step->id,
            attempt: 1,
            status: StepAttemptStatus::Interrupted,
        ));

        $this->assertSame(1, StepAttemptModel::query()->count());

        WorkflowExecutionModel::query()->whereKey($execution->id)->delete();

        $this->assertSame(0, StepAttemptModel::query()->count(), 'Pruning an execution must not orphan history.');
        $this->assertSame(0, StepExecutionModel::query()->count());
    }

    /**
     * @return array{0: ExecutionRepository, 1: WorkflowExecution, 2: StepExecution}
     */
    protected function claimedExecutionWithFirstStep(): array
    {
        $repository = $this->app->make(ExecutionRepository::class);

        $execution = $repository->create(
            workflow: TwoStepWorkflow::class,
            steps: [StepOne::class, StepTwo::class],
        );

        $execution = $repository->claim($execution->id, 'test-worker', 300);

        return [$repository, $execution, $repository->steps($execution)[0]];
    }
}
