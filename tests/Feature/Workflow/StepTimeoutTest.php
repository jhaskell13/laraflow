<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Laraflow\Exceptions\Workflow\StepTimeoutException;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\CrashingExecutionRepository;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\SimulatedWorkerCrash;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;

/**
 * Timeouts are deadlines, not preemption. The policy's timeoutSeconds
 * is durable before handle() runs and exposed to the step; the
 * classification happens at the recovery boundary, giving the three-way
 * distinction: a thrown failure, worker loss before the deadline (free
 * re-run, closed out as interrupted), and an interrupted attempt past
 * its deadline — a timeout, charged to the retry budget.
 */
class StepTimeoutTest extends TestCase
{
    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-10-23 14:00:00');

        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
    }

    // ------------------------------------------------------------------
    // The deadline itself
    // ------------------------------------------------------------------

    public function test_the_deadline_is_durable_before_the_step_runs_and_exposed_to_it(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(timeoutSeconds: 30);

        $this->runner()->run(new PolicyWorkflow);

        $this->assertSame(
            '2026-10-23 14:00:30',
            StepOne::$seenDeadline?->format('Y-m-d H:i:s'),
            'The step must see its own deadline so it can bound its work.',
        );

        // Durability is visible on the row even after completion: the
        // last attempt's deadline is part of its record.
        $this->assertSame(
            '2026-10-23 14:00:30',
            StepExecutionModel::query()->where('position', 0)->sole()->timeout_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_without_a_timeout_there_is_no_deadline(): void
    {
        PolicyWorkflow::$policy = new StepPolicy;

        $this->runner()->run(new PolicyWorkflow);

        $this->assertNull(StepOne::$seenDeadline);
        $this->assertNull(StepExecutionModel::query()->where('position', 0)->sole()->timeout_at);
    }

    // ------------------------------------------------------------------
    // Classification at the recovery boundary
    // ------------------------------------------------------------------

    public function test_an_interrupted_attempt_past_its_deadline_is_charged_to_the_retry_budget(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(
            maxAttempts: 3,
            baseDelaySeconds: 10,
            jitter: 0.0,
            timeoutSeconds: 30,
        );

        $this->crashMidStepOne();

        // Five minutes later: the claim is stale and the deadline long past.
        $this->clock->set('2026-10-23 14:05:00');

        $recovered = $this->runner()->execute(
            WorkflowExecutionModel::query()->value('id'),
            new PolicyWorkflow,
        );

        // Charged, not re-run for free: the overrun became a scheduled
        // retry with the policy's backoff, and the step did not execute.
        $this->assertTrue($recovered->isRetrying());
        $this->assertSame(StepTimeoutException::class, $recovered->errorClass);
        $this->assertSame('2026-10-23 14:05:10', $recovered->nextRetryAt?->format('Y-m-d H:i:s'));
        $this->assertSame(1, StepOne::$runs, 'Classification must not run the step again.');

        $attempt = StepAttemptModel::query()->sole();
        $this->assertSame('timed_out', $attempt->status);
        $this->assertSame(StepTimeoutException::class, $attempt->error_class);
        $this->assertStringContainsString('14:00:30', (string) $attempt->error, 'The record names the missed deadline.');
    }

    public function test_a_timed_out_attempt_with_no_budget_left_fails_terminally(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 1, timeoutSeconds: 30);

        $this->crashMidStepOne();

        $this->clock->set('2026-10-23 14:05:00');

        try {
            $this->runner()->execute(WorkflowExecutionModel::query()->value('id'), new PolicyWorkflow);
            $this->fail('Expected StepTimeoutException.');
        } catch (StepTimeoutException $exception) {
            $this->assertStringContainsString('exceeded its deadline', $exception->getMessage());
            $this->assertStringContainsString('attempt 1', $exception->getMessage());
        }

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $model->status);
        $this->assertSame(StepTimeoutException::class, $model->error_class);

        $this->assertSame(
            StepStatus::Failed->value,
            StepExecutionModel::query()->where('position', 0)->sole()->status,
        );
        $this->assertSame('timed_out', StepAttemptModel::query()->sole()->status);
    }

    public function test_an_interrupted_attempt_before_its_deadline_re_runs_freely(): void
    {
        // A one-attempt policy: if worker loss were charged like a
        // failure, this execution could never finish.
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 1, timeoutSeconds: 600);

        $this->crashMidStepOne();

        // Ten seconds later: claim stale (staleAfter 0), deadline not
        // reached — plain worker loss.
        $this->clock->set('2026-10-23 14:00:10');

        $recovered = $this->runner()->execute(
            WorkflowExecutionModel::query()->value('id'),
            new PolicyWorkflow,
        );

        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(2, StepOne::$runs, 'Worker loss is not the step\'s fault; it re-runs.');

        // The dead attempt is closed out in history, not erased.
        $interrupted = StepAttemptModel::query()->orderBy('attempt')->first();
        $this->assertSame('interrupted', $interrupted?->status);
        $this->assertSame(1, $interrupted->attempt);
        $this->assertSame('2026-10-23 14:00:00', $interrupted->started_at?->format('Y-m-d H:i:s'));
        $this->assertNull($interrupted->duration_ms, 'A dead worker recorded no end.');
    }

    public function test_an_interrupted_attempt_without_any_deadline_also_re_runs_freely(): void
    {
        // No timeout configured at all — the Milestone 1 recovery
        // contract, byte for byte, plus the new history record.
        PolicyWorkflow::$policy = new StepPolicy;

        $this->crashMidStepOne();

        $this->clock->set('2026-10-23 14:10:00');

        $recovered = $this->runner()->execute(
            WorkflowExecutionModel::query()->value('id'),
            new PolicyWorkflow,
        );

        $this->assertTrue($recovered->isCompleted());
        $this->assertSame(2, StepOne::$runs);
        $this->assertSame(
            1,
            StepAttemptModel::query()->where('status', 'interrupted')->count(),
        );
    }

    public function test_a_timed_out_retry_resumes_once_due_and_can_complete(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(
            maxAttempts: 3,
            baseDelaySeconds: 10,
            jitter: 0.0,
            timeoutSeconds: 30,
        );
        $runner = $this->runner();

        $this->crashMidStepOne();

        $this->clock->set('2026-10-23 14:05:00');
        $retrying = $runner->execute(WorkflowExecutionModel::query()->value('id'), new PolicyWorkflow);
        $this->assertTrue($retrying->isRetrying());

        // Once due, the step runs again — with a fresh deadline.
        $this->clock->set('2026-10-23 14:05:10');
        $finished = $runner->execute($retrying->id, new PolicyWorkflow);

        $this->assertTrue($finished->isCompleted());
        $this->assertSame(2, StepOne::$runs);
        $this->assertSame(
            '2026-10-23 14:05:40',
            StepOne::$seenDeadline?->format('Y-m-d H:i:s'),
            'Each attempt gets its own deadline from its own start.',
        );
    }

    protected function crashMidStepOne(): void
    {
        $runner = new WorkflowRunner(
            repository: new CrashingExecutionRepository(
                new EloquentExecutionRepository($this->clock),
                'checkpoint',
                1,
                'before',
            ),
            container: $this->app,
            logger: new ArrayLogger,
            clock: $this->clock,
        );

        try {
            $runner->run(new PolicyWorkflow);
            $this->fail('Expected the simulated crash to propagate.');
        } catch (SimulatedWorkerCrash) {
            // The worker is gone mid-step-one, after its side effect ran
            // but before the checkpoint committed.
        }
    }

    protected function runner(): WorkflowRunner
    {
        return new WorkflowRunner(
            repository: new EloquentExecutionRepository($this->clock),
            container: $this->app,
            logger: new ArrayLogger,
            options: new RunnerOptions(staleAfterSeconds: 0),
            clock: $this->clock,
        );
    }
}
