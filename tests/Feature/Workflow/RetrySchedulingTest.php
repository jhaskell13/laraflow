<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\BadOutputStep;
use Laraflow\Tests\Support\CrashingExecutionRepository;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use LogicException;
use RuntimeException;

/**
 * The runner's side of the retry contract: a retryable failure becomes
 * durable `retrying` state and a returned handle — never an exception,
 * never a failed execution — while terminal failures keep the
 * Milestone 2 contract unchanged.
 *
 * Re-executing a retrying execution (and exhausting a multi-attempt
 * budget) needs claim() to accept the retrying state; that is the next
 * step's contract and is proven there.
 */
class RetrySchedulingTest extends TestCase
{
    protected FixedClock $clock;

    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-10-23 12:00:00');
        $this->logger = new ArrayLogger;

        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
    }

    // ------------------------------------------------------------------
    // Scheduling
    // ------------------------------------------------------------------

    public function test_a_retryable_failure_schedules_a_retry_instead_of_failing(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 10, jitter: 0.0);
        StepTwo::$failTimes = 1;

        // No exception: the runner returns the retrying handle.
        $execution = $this->runner()->run(new PolicyWorkflow);

        $this->assertTrue($execution->isRetrying());
        $this->assertSame('2026-10-23 12:00:10', $execution->nextRetryAt?->format('Y-m-d H:i:s'));
        $this->assertSame('step two blew up', $execution->error, 'Operators must see what the retry is for.');
        $this->assertSame(RuntimeException::class, $execution->errorClass);

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Retrying->value, $model->status);
        $this->assertSame('2026-10-23 12:00:10', $model->next_retry_at?->format('Y-m-d H:i:s'));
        $this->assertNull($model->claim_token, 'A retrying execution waits unclaimed.');
        $this->assertNull($model->failed_at, 'A scheduled retry is not a terminal failure.');

        $step = StepExecutionModel::query()->where('position', 1)->sole();
        $this->assertSame(StepStatus::Retrying->value, $step->status);
        $this->assertSame('2026-10-23 12:00:10', $step->next_retry_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 12:00:00', $step->failed_at?->format('Y-m-d H:i:s'));
        $this->assertSame('step two blew up', $step->error);

        // The checkpointed first step is untouched by the second's retry.
        $this->assertSame(
            StepStatus::Completed->value,
            StepExecutionModel::query()->where('position', 0)->sole()->status,
        );
    }

    public function test_the_schedule_applies_the_policy_backoff_to_the_failed_attempt(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 5, baseDelaySeconds: 60, multiplier: 2.0, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $execution = $this->runner()->run(new PolicyWorkflow);

        // First failure → base delay exactly, no jitter.
        $this->assertSame('2026-10-23 12:01:00', $execution->nextRetryAt?->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------
    // Terminal failures keep the Milestone 2 contract
    // ------------------------------------------------------------------

    public function test_without_any_policy_behaviour_is_unchanged(): void
    {
        // The shipped default (retry.max_attempts = 1): persist failed,
        // rethrow the original exception, schedule nothing.
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('Expected the step exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage());
        }

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $model->status);
        $this->assertNull($model->next_retry_at);
        $this->assertNotNull($model->failed_at);
    }

    public function test_a_spent_budget_fails_terminally(): void
    {
        // maxAttempts counts the first attempt: a budget of 1 means the
        // first failure is the last.
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 1);
        StepTwo::$failTimes = 1;

        $this->expectException(RuntimeException::class);

        try {
            $this->runner()->run(new PolicyWorkflow);
        } finally {
            $this->assertSame(
                ExecutionStatus::Failed->value,
                WorkflowExecutionModel::query()->sole()->status,
            );
        }
    }

    public function test_an_exception_in_dont_retry_on_fails_immediately_despite_budget(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 5, dontRetryOn: [RuntimeException::class]);
        StepTwo::$failTimes = 1;

        $this->expectException(RuntimeException::class);

        try {
            $this->runner()->run(new PolicyWorkflow);
        } finally {
            $this->assertSame(1, StepTwo::$runs, 'One attempt, no scheduling.');
            $this->assertNull(WorkflowExecutionModel::query()->sole()->next_retry_at);
        }
    }

    public function test_an_exception_outside_retry_on_fails_immediately(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 5, retryOn: [LogicException::class]);
        StepTwo::$failTimes = 1;

        $this->expectException(RuntimeException::class);

        $this->runner()->run(new PolicyWorkflow);
    }

    public function test_serialization_violations_are_never_retried(): void
    {
        PolicyWorkflow::$steps = [BadOutputStep::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 5);

        try {
            $this->runner()->run(new PolicyWorkflow);
            $this->fail('Expected UnserializableValueException.');
        } catch (UnserializableValueException) {
            // Expected: a deterministic bug repeats; retrying it is noise.
        }

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    // ------------------------------------------------------------------
    // Attempt history
    // ------------------------------------------------------------------

    public function test_history_records_the_completed_and_the_retry_scheduled_attempts(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 10, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $this->runner()->run(new PolicyWorkflow);

        $stepOneId = StepExecutionModel::query()->where('position', 0)->value('id');
        $completed = StepAttemptModel::query()->where('step_execution_id', $stepOneId)->sole();
        $this->assertSame('completed', $completed->status);
        $this->assertSame(1, $completed->attempt);
        $this->assertNull($completed->error);

        $stepTwoId = StepExecutionModel::query()->where('position', 1)->value('id');
        $failed = StepAttemptModel::query()->where('step_execution_id', $stepTwoId)->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, $failed->attempt);
        $this->assertSame('step two blew up', $failed->error);
        $this->assertSame(RuntimeException::class, $failed->error_class);
        $this->assertSame(
            '2026-10-23 12:00:10',
            $failed->next_retry_at?->format('Y-m-d H:i:s'),
            'History records the retry each failed attempt scheduled.',
        );
    }

    public function test_a_terminal_failure_records_its_attempt_without_a_retry(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $stepTwoId = StepExecutionModel::query()->where('position', 1)->value('id');
        $attempt = StepAttemptModel::query()->where('step_execution_id', $stepTwoId)->sole();

        $this->assertSame('failed', $attempt->status);
        $this->assertNull($attempt->next_retry_at, 'No retry was scheduled, so history says so.');
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    public function test_a_scheduled_retry_fires_step_failed_and_retry_scheduled_but_never_execution_failed(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 10, jitter: 0.0);
        StepTwo::$failTimes = 1;

        Event::fake([
            WorkflowStepFailed::class,
            WorkflowStepRetryScheduled::class,
            WorkflowExecutionFailed::class,
        ]);

        $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);

        Event::assertDispatchedTimes(WorkflowStepFailed::class, 1);
        Event::assertDispatched(
            WorkflowStepRetryScheduled::class,
            function (WorkflowStepRetryScheduled $event): bool {
                return $event->step === StepTwo::class
                    && $event->position === 1
                    && $event->attempt === 1
                    && $event->maxAttempts === 3
                    && $event->errorClass === RuntimeException::class
                    && $event->error === 'step two blew up'
                    && $event->delaySeconds === 10;
            },
        );
        // An execution that will heal itself must not page anyone.
        Event::assertNotDispatched(WorkflowExecutionFailed::class);
    }

    public function test_retry_scheduled_respects_the_error_message_opt_out(): void
    {
        config(['laraflow.events.include_error_messages' => false]);
        $this->app->forgetInstance(WorkflowRunner::class);

        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 10, jitter: 0.0);
        StepTwo::$failTimes = 1;

        Event::fake([WorkflowStepRetryScheduled::class]);

        $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);

        Event::assertDispatched(
            WorkflowStepRetryScheduled::class,
            fn (WorkflowStepRetryScheduled $event): bool => $event->error === null
                && $event->errorClass === RuntimeException::class,
        );
    }

    // ------------------------------------------------------------------
    // Storage failure while scheduling
    // ------------------------------------------------------------------

    public function test_the_original_exception_survives_when_the_retry_cannot_be_persisted(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 10, jitter: 0.0);
        StepTwo::$failTimes = 1;

        // Call sequence: saveStep #1 and #2 mark the steps running;
        // checkpoint persists step one; saveStep #3 records the retry —
        // storage dies there, exactly as a failing database would.
        $runner = new WorkflowRunner(
            repository: new CrashingExecutionRepository(
                new EloquentExecutionRepository($this->clock),
                'saveStep',
                3,
                'before',
            ),
            container: $this->app,
            logger: $this->logger,
            clock: $this->clock,
        );

        try {
            $runner->run(new PolicyWorkflow);
            $this->fail('Expected the original RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage(), 'Diagnosis must never be masked.');
        }

        $this->assertTrue(
            $this->logger->hasRecordContaining('error', 'could not persist a scheduled retry'),
        );

        // The claim is still held, so stale-claim recovery owns this.
        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Running->value, $model->status);
        $this->assertNotNull($model->claim_token);
    }

    protected function runner(): WorkflowRunner
    {
        // The policy under test comes from the workflow; runner and
        // repository share one fixed clock so the schedule is exact.
        return new WorkflowRunner(
            repository: new EloquentExecutionRepository($this->clock),
            container: $this->app,
            logger: $this->logger,
            clock: $this->clock,
        );
    }
}
