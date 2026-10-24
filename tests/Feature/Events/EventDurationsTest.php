<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Events;

use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Durations on lifecycle events: how long each attempt took, and how
 * long the execution took end to end.
 *
 * Driven by an injected fixed clock, so every number here is exact
 * rather than a tolerance — the point of the clock abstraction.
 */
class EventDurationsTest extends TestCase
{
    protected FixedClock $clock;

    /** @var list<object> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-10-24 12:00:00');
        $this->app->instance(ClockInterface::class, $this->clock);

        $this->events = [];

        foreach ([
            WorkflowStepCompleted::class,
            WorkflowStepFailed::class,
            WorkflowExecutionCompleted::class,
            WorkflowExecutionFailed::class,
            WorkflowExecutionCancelled::class,
        ] as $class) {
            Event::listen($class, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    // ------------------------------------------------------------------
    // Step attempts
    // ------------------------------------------------------------------

    public function test_a_completed_step_reports_how_long_the_attempt_ran(): void
    {
        StepOne::$beforeReturn = fn () => $this->clock->advance(3);

        $this->runner()->run(new TwoStepWorkflow);

        $first = $this->first(WorkflowStepCompleted::class);

        $this->assertSame(3000, $first->durationMs);
    }

    public function test_an_instantaneous_step_reports_zero_rather_than_null(): void
    {
        $this->runner()->run(new TwoStepWorkflow);

        // Zero is a measurement; null would mean "not measured".
        $this->assertSame(0, $this->first(WorkflowStepCompleted::class)->durationMs);
    }

    public function test_a_failed_step_reports_how_long_the_attempt_ran(): void
    {
        StepTwo::$failTimes = 1;
        StepTwo::$beforeReturn = null;

        $this->advanceInsideStepTwo(5);

        try {
            $this->runner()->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected: the shipped policy does not retry.
        }

        $this->assertSame(5000, $this->first(WorkflowStepFailed::class)->durationMs);
    }

    public function test_the_event_duration_matches_the_stored_attempt(): void
    {
        StepOne::$beforeReturn = fn () => $this->clock->advance(2);

        $this->runner()->run(new TwoStepWorkflow);

        $stored = StepAttemptModel::query()->where('attempt', 1)->orderBy('id')->firstOrFail();

        // One record feeds both, so history and telemetry cannot
        // disagree about how long a step took.
        $this->assertSame(
            $stored->duration_ms,
            $this->first(WorkflowStepCompleted::class)->durationMs,
        );
    }

    public function test_a_retrying_failure_reports_its_own_attempt_duration(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $this->advanceInsideStepTwo(4);

        $this->runner()->run(new PolicyWorkflow);

        $failed = $this->first(WorkflowStepFailed::class);

        // The failed attempt's own duration, not the time until the
        // retry is due.
        $this->assertSame(4000, $failed->durationMs);
    }

    // ------------------------------------------------------------------
    // Executions
    // ------------------------------------------------------------------

    public function test_a_completed_execution_reports_its_end_to_end_duration(): void
    {
        StepOne::$beforeReturn = fn () => $this->clock->advance(2);
        $this->advanceInsideStepTwo(3);

        $this->runner()->run(new TwoStepWorkflow);

        $this->assertSame(5000, $this->first(WorkflowExecutionCompleted::class)->durationMs);
    }

    public function test_a_failed_execution_reports_its_duration(): void
    {
        StepTwo::$failTimes = 1;
        StepOne::$beforeReturn = fn () => $this->clock->advance(1);

        try {
            $this->runner()->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(1000, $this->first(WorkflowExecutionFailed::class)->durationMs);
    }

    public function test_a_recovered_execution_measures_from_its_first_start(): void
    {
        StepTwo::$failTimes = 1;

        $execution = $this->runner()->start(new TwoStepWorkflow);

        try {
            $this->runner()->execute($execution->id, new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected: the first attempt fails.
        }

        // An hour passes with the execution sitting failed.
        $this->clock->advance(3600);
        $this->runner()->execute($execution->id, new TwoStepWorkflow);

        $completed = $this->first(WorkflowExecutionCompleted::class);

        // End-to-end latency, deliberately including the hour it spent
        // failed: this is what an SLA measures. Per-attempt compute time
        // lives on the step events.
        $this->assertSame(3_600_000, $completed->durationMs);
    }

    public function test_a_cancelled_execution_reports_its_duration(): void
    {
        $execution = $this->runner()->start(new TwoStepWorkflow);
        $this->clock->advance(30);

        $this->runner()->cancel($execution->id, 'customer withdrew');

        $cancelled = $this->first(WorkflowExecutionCancelled::class);

        // Never started, so there is no span to report — cancelling
        // work that never ran took no time, it simply never began.
        $this->assertNull($cancelled->durationMs);
    }

    public function test_a_cancellation_during_a_run_reports_the_elapsed_time(): void
    {
        $execution = $this->runner()->start(new TwoStepWorkflow);

        StepOne::$beforeReturn = function () use ($execution) {
            $this->clock->advance(10);
            // Requested by another process while this worker runs; it is
            // honoured at the next step boundary.
            $this->repository()->requestCancellation($execution->id, 'stop', $this->clock->now());
        };

        $result = $this->runner()->execute($execution->id, new TwoStepWorkflow);

        $this->assertTrue($result->isCancelled());
        $this->assertSame(10_000, $this->first(WorkflowExecutionCancelled::class)->durationMs);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Advance the clock while step two is in flight.
     *
     * Uses $beforeRun, not $beforeReturn: the latter sits after the
     * step's throw, so a failing attempt would look instantaneous.
     */
    protected function advanceInsideStepTwo(int $seconds): void
    {
        StepTwo::$beforeRun = fn () => $this->clock->advance($seconds);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function first(string $class): object
    {
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        $this->fail("No {$class} event was fired.");
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }

    protected function repository(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
