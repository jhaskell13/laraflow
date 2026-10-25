<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\SecretiveWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use LogicException;
use RuntimeException;

class LifecycleEventsTest extends TestCase
{
    protected const LIFECYCLE_EVENTS = [
        WorkflowExecutionStarted::class,
        WorkflowStepStarted::class,
        WorkflowStepCompleted::class,
        WorkflowStepFailed::class,
        WorkflowExecutionCompleted::class,
        WorkflowExecutionFailed::class,
    ];

    /** @var list<object> */
    protected array $events = [];

    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        $this->logger = new ArrayLogger;

        foreach (self::LIFECYCLE_EVENTS as $class) {
            Event::listen($class, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    // ------------------------------------------------------------------
    // Success path
    // ------------------------------------------------------------------

    public function test_a_successful_run_fires_the_lifecycle_events_in_order(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)
            ->run(new TwoStepWorkflow, ['customer_id' => 42]);

        $this->assertSame([
            WorkflowExecutionStarted::class,
            WorkflowStepStarted::class,
            WorkflowStepCompleted::class,
            WorkflowStepStarted::class,
            WorkflowStepCompleted::class,
            WorkflowExecutionCompleted::class,
        ], array_map(fn (object $event): string => $event::class, $this->events));

        $started = $this->events[0];
        $this->assertInstanceOf(WorkflowExecutionStarted::class, $started);
        $this->assertSame($execution->id, $started->executionId);
        $this->assertSame(TwoStepWorkflow::class, $started->workflow);

        $stepStarted = $this->events[1];
        $this->assertInstanceOf(WorkflowStepStarted::class, $stepStarted);
        $this->assertSame(StepOne::class, $stepStarted->step);
        $this->assertSame(0, $stepStarted->position);
        $this->assertSame(1, $stepStarted->attempt);

        $secondCompleted = $this->events[4];
        $this->assertInstanceOf(WorkflowStepCompleted::class, $secondCompleted);
        $this->assertSame(StepTwo::class, $secondCompleted->step);
        $this->assertSame(1, $secondCompleted->position);

        $completed = $this->events[5];
        $this->assertInstanceOf(WorkflowExecutionCompleted::class, $completed);
        $this->assertSame($execution->id, $completed->executionId);
    }

    public function test_step_completed_fires_only_after_the_checkpoint_has_committed(): void
    {
        $observedStatuses = [];

        Event::listen(
            WorkflowStepCompleted::class,
            function (WorkflowStepCompleted $event) use (&$observedStatuses): void {
                $observedStatuses[] = StepExecutionModel::query()
                    ->where('workflow_execution_id', $event->executionId)
                    ->where('position', $event->position)
                    ->value('status');
            }
        );

        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $this->assertSame(
            [StepStatus::Completed->value, StepStatus::Completed->value],
            $observedStatuses,
        );
    }

    // ------------------------------------------------------------------
    // Failure path
    // ------------------------------------------------------------------

    public function test_a_step_failure_fires_step_failed_then_execution_failed(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);
            $this->fail('The step exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame([
            WorkflowExecutionStarted::class,
            WorkflowStepStarted::class,
            WorkflowStepCompleted::class,
            WorkflowStepStarted::class,
            WorkflowStepFailed::class,
            WorkflowExecutionFailed::class,
        ], array_map(fn (object $event): string => $event::class, $this->events));

        $stepFailed = $this->events[4];
        $this->assertInstanceOf(WorkflowStepFailed::class, $stepFailed);
        $this->assertSame(StepTwo::class, $stepFailed->step);
        $this->assertSame(1, $stepFailed->attempt);
        $this->assertSame(RuntimeException::class, $stepFailed->errorClass);
        $this->assertSame('step two blew up', $stepFailed->error);

        $executionFailed = $this->events[5];
        $this->assertInstanceOf(WorkflowExecutionFailed::class, $executionFailed);
        $this->assertSame(RuntimeException::class, $executionFailed->errorClass);
        $this->assertSame('step two blew up', $executionFailed->error);
    }

    public function test_error_messages_can_be_excluded_from_failure_events(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner(includeErrorMessages: false)->run(new TwoStepWorkflow);
            $this->fail('The step exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        $stepFailed = $this->events[4];
        $this->assertInstanceOf(WorkflowStepFailed::class, $stepFailed);
        $this->assertSame(RuntimeException::class, $stepFailed->errorClass);
        $this->assertNull($stepFailed->error);

        $executionFailed = $this->events[5];
        $this->assertInstanceOf(WorkflowExecutionFailed::class, $executionFailed);
        $this->assertNull($executionFailed->error);

        // The exclusion applies to events only, not to persistence.
        $this->assertSame(
            'step two blew up',
            WorkflowExecutionModel::query()->sole()->error,
        );
    }

    public function test_error_messages_in_events_are_truncated_like_persisted_errors(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner(maxErrorLength: 8)->run(new TwoStepWorkflow);
            $this->fail('The step exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        $executionFailed = $this->events[5];
        $this->assertInstanceOf(WorkflowExecutionFailed::class, $executionFailed);
        $this->assertSame('step two', $executionFailed->error);
    }

    // ------------------------------------------------------------------
    // Recovery and claiming
    // ------------------------------------------------------------------

    public function test_recovery_fires_started_again_with_the_original_started_at(): void
    {
        StepTwo::$failTimes = 1;

        Date::setTestNow('2026-10-22 10:00:00');

        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);

        try {
            $runner->execute($execution->id, new TwoStepWorkflow);
            $this->fail('The step exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        $firstStarted = $this->events[0];
        $this->assertInstanceOf(WorkflowExecutionStarted::class, $firstStarted);

        $this->events = [];
        Date::setTestNow('2026-10-22 11:00:00');

        $runner->execute($execution->id, new TwoStepWorkflow);

        $this->assertSame([
            WorkflowExecutionStarted::class,
            WorkflowStepStarted::class,
            WorkflowStepCompleted::class,
            WorkflowExecutionCompleted::class,
        ], array_map(fn (object $event): string => $event::class, $this->events));

        $secondStarted = $this->events[0];
        $this->assertInstanceOf(WorkflowExecutionStarted::class, $secondStarted);
        $this->assertEquals($firstStarted->startedAt, $secondStarted->startedAt);

        $retried = $this->events[1];
        $this->assertInstanceOf(WorkflowStepStarted::class, $retried);
        $this->assertSame(2, $retried->attempt);
    }

    public function test_no_events_fire_when_the_claim_cannot_be_taken(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new TwoStepWorkflow);

        // Another worker holds a fresh claim.
        $this->app->make(ExecutionRepository::class)
            ->claim($execution->id, str_repeat('ab', 16), 300);
        $this->events = [];

        try {
            $runner->execute($execution->id, new TwoStepWorkflow);
            $this->fail('The claim should have been refused.');
        } catch (ExecutionAlreadyClaimedException) {
            // Expected.
        }

        $this->assertSame([], $this->events);
    }

    // ------------------------------------------------------------------
    // Listener failures
    // ------------------------------------------------------------------

    public function test_a_throwing_listener_is_logged_and_does_not_affect_the_run(): void
    {
        Event::listen(WorkflowStepCompleted::class, function (): void {
            throw new LogicException('listener exploded');
        });

        $execution = $this->runner()->run(new TwoStepWorkflow);

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(ExecutionStatus::Completed->value, WorkflowExecutionModel::query()->sole()->status);

        $this->assertTrue($this->logger->hasRecordContaining('error', 'lifecycle event listener threw'));
    }

    public function test_a_throwing_listener_does_not_mask_a_step_failure(): void
    {
        StepTwo::$failTimes = 1;

        Event::listen(WorkflowStepFailed::class, function (): void {
            throw new LogicException('listener exploded');
        });

        try {
            $this->runner()->run(new TwoStepWorkflow);
            $this->fail('The step exception should have propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('step two blew up', $exception->getMessage());
        }

        // The failure was persisted and the following event still fired.
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
        $this->assertInstanceOf(WorkflowExecutionFailed::class, end($this->events));
        $this->assertTrue($this->logger->hasRecordContaining('error', 'lifecycle event listener threw'));
    }

    // ------------------------------------------------------------------
    // Payload safety
    // ------------------------------------------------------------------

    public function test_events_never_contain_workflow_input_output_or_context(): void
    {
        $secret = 'tok_SENTINEL_f81cd3';

        $this->app->make(WorkflowRunner::class)
            ->run(new SecretiveWorkflow, ['secret' => $secret]);

        // The secret reached every durable payload...
        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame($secret, $model->input['secret'] ?? null);
        $this->assertSame($secret, $model->context['copied_secret'] ?? null);
        $this->assertSame(
            $secret,
            StepExecutionModel::query()->sole()->output['secret_output'] ?? null,
        );

        // ...but no event carries it.
        $this->assertNotSame([], $this->events);

        foreach ($this->events as $event) {
            $serialized = json_encode(get_object_vars($event), JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString($secret, $serialized, sprintf(
                'Event [%s] leaked a durable payload value.',
                $event::class,
            ));
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function runner(
        bool $includeErrorMessages = true,
        int $maxErrorLength = 65535,
    ): WorkflowRunner {
        return new WorkflowRunner(
            repository: $this->app->make(ExecutionRepository::class),
            container: $this->app,
            logger: $this->logger,
            options: new RunnerOptions(maxErrorLength: $maxErrorLength, includeErrorMessages: $includeErrorMessages),
            events: new EventEmitter($this->app->make(Dispatcher::class), $this->logger),
        );
    }
}
