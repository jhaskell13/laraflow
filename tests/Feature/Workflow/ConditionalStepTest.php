<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowStepSkipped;
use Laraflow\Tests\Support\ConditionalGate;
use Laraflow\Tests\Support\GatedWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The conditional-step contract (docs/advanced-workflows.md): a false
 * condition is a durable, attempt-free, context-neutral decision that
 * recovery replays rather than re-evaluates.
 */
class ConditionalStepTest extends TestCase
{
    public function test_a_false_condition_skips_the_step_durably_and_the_rest_run(): void
    {
        Event::fake([WorkflowStepSkipped::class]);
        ConditionalGate::$open = false;

        $execution = $this->runner()->run(new GatedWorkflow, ['customer_id' => 7]);

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(0, ConditionalGate::$runs, 'A skipped step must not run.');
        $this->assertSame(1, StepTwo::$runs, 'Steps after the skip still run.');

        $row = StepExecutionModel::query()->where('position', 1)->firstOrFail();
        $this->assertSame(StepStatus::Skipped->value, $row->status);
        $this->assertSame(0, $row->attempts, 'A skip consumes no attempt.');
        $this->assertNull($row->output, 'A skip contributes nothing.');
        $this->assertNotNull($row->completed_at, 'The decision instant is recorded.');

        $this->assertSame(
            0,
            StepAttemptModel::query()->where('step_execution_id', $row->id)->count(),
            'No attempt-history row for a skip.',
        );

        // The skipped step's context key is absent, not null-filled.
        $this->assertArrayNotHasKey('gate', WorkflowExecutionModel::query()->sole()->context ?? []);

        Event::assertDispatched(
            WorkflowStepSkipped::class,
            fn (WorkflowStepSkipped $event): bool => $event->step === ConditionalGate::class
                && $event->position === 1
                && $event->executionId === $execution->id,
        );
    }

    public function test_a_true_condition_runs_the_step_and_records_nothing_extra(): void
    {
        Event::fake([WorkflowStepSkipped::class]);
        ConditionalGate::$open = true;

        $execution = $this->runner()->run(new GatedWorkflow);

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(1, ConditionalGate::$runs);
        $this->assertSame(
            StepStatus::Completed->value,
            StepExecutionModel::query()->where('position', 1)->value('status'),
        );
        Event::assertNotDispatched(WorkflowStepSkipped::class);
    }

    public function test_recovery_replays_the_recorded_skip_instead_of_re_evaluating(): void
    {
        // Step two (after the gate) fails once, leaving a failed
        // execution with the gate durably skipped.
        ConditionalGate::$open = false;
        StepTwo::$failTimes = 1;

        $runner = $this->runner();
        $execution = $runner->start(new GatedWorkflow);

        try {
            $runner->execute($execution->id, new GatedWorkflow);
            $this->fail('Expected step two to fail.');
        } catch (RuntimeException) {
            // Expected.
        }

        // The world changes its mind — but the decision is recorded,
        // and an impure condition must not get a second vote.
        ConditionalGate::$open = true;

        $recovered = $runner->execute($execution->id, new GatedWorkflow);

        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(0, ConditionalGate::$runs, 'The recorded skip replays; the condition is not re-asked.');
        $this->assertSame(
            StepStatus::Skipped->value,
            StepExecutionModel::query()->where('position', 1)->value('status'),
        );
    }

    public function test_a_throwing_condition_fails_the_step_like_a_definition_fault(): void
    {
        ConditionalGate::$throw = new RuntimeException('cannot decide');

        try {
            $this->runner()->run(new GatedWorkflow);
            $this->fail('Expected the condition failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cannot decide', $exception->getMessage());
        }

        $row = StepExecutionModel::query()->where('position', 1)->firstOrFail();
        $this->assertSame(StepStatus::Failed->value, $row->status);
        $this->assertSame(0, ConditionalGate::$runs);
    }

    public function test_step_progress_counts_skips_as_their_own_kind(): void
    {
        ConditionalGate::$open = false;

        $execution = $this->runner()->run(new GatedWorkflow);

        $progress = $this->app->make(ExecutionRepository::class)->stepProgress([$execution->id]);

        $this->assertSame(
            ['total' => 3, 'completed' => 2, 'skipped' => 1],
            $progress[(string) $execution->id],
        );
    }

    public function test_under_continue_a_skip_is_not_persisted_while_an_earlier_step_has_failed(): void
    {
        // A fails and contributes nothing; the gate reads the key A
        // would have written. Its false answer is not final — the
        // context is not final — so nothing durable is recorded.
        GatedWorkflow::$failurePolicy = FailurePolicy::Continue;
        ConditionalGate::$readsKey = 'one';
        StepOne::$throw = new RuntimeException('step one down');

        $runner = $this->runner();
        $execution = $runner->start(new GatedWorkflow);

        try {
            $runner->execute($execution->id, new GatedWorkflow);
            $this->fail('Expected the continue-policy run to fail at the end.');
        } catch (RuntimeException) {
            // The first failure surfaces once everything ran.
        }

        $this->assertSame(
            StepStatus::Pending->value,
            StepExecutionModel::query()->where('position', 1)->value('status'),
            'The skip must not be durable: the context it judged was not final.',
        );
        $this->assertSame(1, StepTwo::$runs, 'Later steps still ran under Continue.');

        // Re-execution: A succeeds and writes the key; the condition
        // gets its real vote against the full context and the gate runs.
        $recovered = $runner->execute($execution->id, new GatedWorkflow);

        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(1, ConditionalGate::$runs, 'The condition answered true against the final context.');
    }

    public function test_after_a_throwing_condition_re_execution_consults_the_condition_again(): void
    {
        // The condition throws once (a transient fault) and the step is
        // durably failed. On re-execution the condition — which never
        // answered — must get its vote, not be presumed true.
        ConditionalGate::$throw = new RuntimeException('cannot decide');
        ConditionalGate::$open = false;

        $runner = $this->runner();
        $execution = $runner->start(new GatedWorkflow);

        try {
            $runner->execute($execution->id, new GatedWorkflow);
            $this->fail('Expected the condition failure.');
        } catch (RuntimeException) {
            // Durably failed.
        }

        $recovered = $runner->execute($execution->id, new GatedWorkflow);

        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(0, ConditionalGate::$runs, 'The step body must not run: the condition answered false.');
        $this->assertSame(
            StepStatus::Skipped->value,
            StepExecutionModel::query()->where('position', 1)->value('status'),
        );
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
