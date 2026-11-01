<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\IndicatesUncertainOutcome;
use Laraflow\Exceptions\Workflow\IdempotencyStoreUnavailableException;
use Laraflow\Exceptions\Workflow\IdempotentOutcomeUncertainException;
use Laraflow\Exceptions\Workflow\InvalidIdempotencyKeyException;
use Laraflow\Exceptions\Workflow\TooManyIdempotencyKeysException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\RememberingStep;
use Laraflow\Tests\Support\RememberingWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\IdempotencyRecord;
use Laraflow\Workflow\IdempotencyResolver;
use Laraflow\Workflow\IdempotencyStatus;
use Laraflow\Workflow\IdempotencyStore;
use Laraflow\Workflow\Models\IdempotencyKeyModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\RetentionPolicy;
use Laraflow\Workflow\RetentionService;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\WorkflowContext;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * The contract in docs/idempotency.md: remembered work runs at most
 * once per execution, a crash between the marker and the value is
 * detected rather than guessed at, and the store follows payload
 * through retention.
 */
class IdempotencyTest extends TestCase
{
    use RunsArtisanCommands;

    // ------------------------------------------------------------------
    // At most once
    // ------------------------------------------------------------------

    public function test_remembered_work_runs_once_across_a_retry(): void
    {
        // The point of the store: the step charges, then fails on the
        // next line; the retry must not charge again.
        RememberingWorkflow::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 0, jitter: 0.0);
        RememberingStep::$failAfterWorkTimes = 1;

        $execution = $this->runner()->run(new RememberingWorkflow);
        $this->assertTrue($execution->isRetrying());
        $this->assertSame(1, RememberingStep::$effects);

        $result = $this->runner()->execute($execution->id, new RememberingWorkflow, ignoreRetrySchedule: true);

        $this->assertTrue($result->isCompleted());
        $this->assertSame(2, RememberingStep::$runs, 'The step ran twice.');
        $this->assertSame(1, RememberingStep::$effects, 'The side effect happened once.');
        $this->assertSame('CH-1', RememberingStep::$remembered, 'The retry got the stored value.');
    }

    public function test_the_value_survives_a_terminal_failure_and_an_operator_re_run(): void
    {
        RememberingStep::$failAfterWorkTimes = 1;
        $execution = $this->startAndFail();

        $result = $this->runner()->execute($execution->id, new RememberingWorkflow);

        $this->assertTrue($result->isCompleted());
        $this->assertSame(1, RememberingStep::$effects, 'An operator re-run must not repeat the side effect.');
        $this->assertSame(IdempotencyStatus::Settled, $this->record($execution)->status);
    }

    public function test_a_stored_null_is_a_value_not_an_absence(): void
    {
        RememberingStep::$value = null;
        RememberingStep::$failAfterWorkTimes = 1;
        $execution = $this->startAndFail();

        $this->runner()->execute($execution->id, new RememberingWorkflow);

        $this->assertSame(1, RememberingStep::$effects, 'A settled null must be replayed, not re-run.');
        $this->assertNull(RememberingStep::$remembered);
    }

    public function test_keys_are_scoped_to_the_step_not_the_execution(): void
    {
        RememberingWorkflow::$steps = [RememberingStep::class, RememberingStep::class];

        $execution = $this->runner()->run(new RememberingWorkflow);

        $this->assertTrue($execution->isCompleted());
        $this->assertSame(2, RememberingStep::$effects, 'Two steps under the same key are two pieces of work.');
        $this->assertSame(
            ["laraflow:{$execution->id}:0:charge", "laraflow:{$execution->id}:1:charge"],
            array_map(fn (IdempotencyRecord $r) => $r->key, $this->store()->records($execution->id)),
        );
    }

    // ------------------------------------------------------------------
    // A thrown exception is a known outcome; a dead process is not
    // ------------------------------------------------------------------

    public function test_a_thrown_exception_is_a_known_outcome_and_the_next_attempt_starts_over(): void
    {
        RememberingStep::$workThrows = new RuntimeException('gateway said no');
        $execution = $this->startAndFail();

        $record = $this->record($execution);
        $this->assertSame(IdempotencyStatus::Failed, $record->status);
        $this->assertSame(RuntimeException::class, $record->errorClass);
        $this->assertSame(0, RememberingStep::$effects);

        $result = $this->runner()->execute($execution->id, new RememberingWorkflow);

        $this->assertTrue($result->isCompleted());
        $this->assertSame(1, RememberingStep::$effects, 'A known failure is retried from the start.');
        $this->assertSame(IdempotencyStatus::Settled, $this->record($execution)->status);
    }

    public function test_an_exception_indicating_uncertainty_leaves_the_record_pending(): void
    {
        RememberingStep::$workThrows = new class('timed out after sending') extends RuntimeException implements IndicatesUncertainOutcome {};
        $execution = $this->startAndFail();

        $this->assertSame(IdempotencyStatus::Pending, $this->record($execution)->status, 'The exception said the outcome is unknown.');

        $this->expectException(IdempotentOutcomeUncertainException::class);

        $this->runner()->execute($execution->id, new RememberingWorkflow);
    }

    public function test_a_worker_dying_between_the_marker_and_the_value_leaves_an_uncertain_outcome(): void
    {
        // The window the whole design exists to detect: the side effect
        // happened, and the process died before the value was written.
        $execution = $this->runAndDieAtTheValueWrite();

        $this->assertSame(1, RememberingStep::$effects, 'The side effect happened.');
        $this->assertSame(IdempotencyStatus::Pending, $this->record($execution)->status, 'The marker is the evidence.');

        try {
            $this->runner()->execute($execution->id, new RememberingWorkflow);
            $this->fail('An unknown outcome must be raised, not guessed.');
        } catch (IdempotentOutcomeUncertainException $exception) {
            $this->assertStringContainsString('[charge]', $exception->getMessage());
            $this->assertStringContainsString('may or may not have taken place', $exception->getMessage());
        }

        $this->assertSame(1, RememberingStep::$effects, 'Raising means the work did not run again.');
    }

    public function test_the_uncertain_outcome_is_never_retried(): void
    {
        RememberingWorkflow::$policy = new StepPolicy(maxAttempts: 5, baseDelaySeconds: 0, jitter: 0.0);
        $execution = $this->runAndDieAtTheValueWrite();

        try {
            $this->runner()->execute($execution->id, new RememberingWorkflow);
        } catch (IdempotentOutcomeUncertainException) {
            // Expected.
        }

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->whereKey($execution->id)->sole()->status,
            'A retry would find the same pending record and ask the same question.',
        );
    }

    // ------------------------------------------------------------------
    // Reconciling
    // ------------------------------------------------------------------

    public function test_a_reconciler_resolves_an_uncertain_outcome_without_running_the_work(): void
    {
        $execution = $this->runAndDieAtTheValueWrite();

        RememberingStep::$reconcile = fn (): string => 'CH-FOUND-AT-PROVIDER';

        $result = $this->runner()->execute($execution->id, new RememberingWorkflow);

        $this->assertTrue($result->isCompleted());
        $this->assertSame(1, RememberingStep::$effects, 'The provider answered; the work did not run again.');
        $this->assertSame('CH-FOUND-AT-PROVIDER', RememberingStep::$remembered);

        $record = $this->record($execution);
        $this->assertSame(IdempotencyStatus::Settled, $record->status);
        $this->assertSame('CH-FOUND-AT-PROVIDER', $record->value);
    }

    public function test_a_reconciler_answering_null_asserts_nothing_landed_and_the_work_runs_again(): void
    {
        $execution = $this->runAndDieAtTheValueWrite();

        RememberingStep::$reconcile = fn (): mixed => null;

        $result = $this->runner()->execute($execution->id, new RememberingWorkflow);

        $this->assertTrue($result->isCompleted());
        $this->assertSame(2, RememberingStep::$effects, 'Null is the application asserting the side effect never landed.');
        $this->assertSame(IdempotencyStatus::Settled, $this->record($execution)->status);
    }

    public function test_a_reconciler_is_not_consulted_when_the_outcome_is_known(): void
    {
        $asked = 0;
        RememberingStep::$reconcile = function () use (&$asked): string {
            $asked++;

            return 'never';
        };

        $this->runner()->run(new RememberingWorkflow);

        $this->assertSame(0, $asked);
        $this->assertSame('CH-1', RememberingStep::$remembered);
    }

    // ------------------------------------------------------------------
    // Two workers
    // ------------------------------------------------------------------

    public function test_two_workers_cannot_both_run_the_work(): void
    {
        // A stalled worker has begun the work under this key and not
        // finished. The worker that reclaims the execution must not run
        // it too; the unique key tells it the outcome is uncertain.
        $execution = $this->runner()->start(new RememberingWorkflow);

        $existing = $this->store()->begin($execution->id, 0, "laraflow:{$execution->id}:0:charge", new DateTimeImmutable);
        $this->assertNull($existing, 'The stalled worker created the marker.');

        try {
            $this->runner()->execute($execution->id, new RememberingWorkflow);
            $this->fail('The second worker must not run the work.');
        } catch (IdempotentOutcomeUncertainException) {
            // The second begin() lost the race and found a pending record.
        }

        $this->assertSame(0, RememberingStep::$effects);
    }

    // ------------------------------------------------------------------
    // Bounds and validation
    // ------------------------------------------------------------------

    public function test_the_per_execution_cap_is_enforced_and_the_refused_marker_released(): void
    {
        $options = new RunnerOptions(maxIdempotencyKeysPerExecution: 2);
        $runner = $this->makeRunner(
            options: $options,
            idempotency: new IdempotencyResolver($this->store(), $this->app->make(ClockInterface::class), $options),
        );
        RememberingStep::$extraKeys = ['second', 'third'];

        $execution = $runner->start(new RememberingWorkflow);

        try {
            $runner->execute($execution->id, new RememberingWorkflow);
            $this->fail('The third key is past the cap.');
        } catch (TooManyIdempotencyKeysException $exception) {
            $this->assertStringContainsString('max_keys_per_execution', $exception->getMessage());
        }

        $this->assertSame(2, $this->store()->count($execution->id), 'The refused marker was released.');
    }

    public function test_a_replay_is_never_refused_at_the_cap(): void
    {
        // The cap is checked after the insert, so a key that already
        // exists is found rather than counted.
        $options = new RunnerOptions(maxIdempotencyKeysPerExecution: 1);
        $runner = $this->makeRunner(
            options: $options,
            idempotency: new IdempotencyResolver($this->store(), $this->app->make(ClockInterface::class), $options),
        );
        RememberingStep::$failAfterWorkTimes = 1;

        $execution = $runner->start(new RememberingWorkflow);

        try {
            $runner->execute($execution->id, new RememberingWorkflow);
        } catch (RuntimeException) {
            // The fixture's failure after the work.
        }

        $this->assertTrue($runner->execute($execution->id, new RememberingWorkflow)->isCompleted());
        $this->assertSame(1, RememberingStep::$effects);
    }

    public function test_invalid_keys_are_refused_before_anything_is_stored(): void
    {
        foreach (['' => 'must not be empty', "ch\narge" => 'control characters', str_repeat('k', 300) => 'limit is 255'] as $key => $message) {
            RememberingStep::reset();
            RememberingStep::$key = $key;

            $execution = $this->runner()->start(new RememberingWorkflow);

            try {
                $this->runner()->execute($execution->id, new RememberingWorkflow);
                $this->fail("Key [{$key}] was accepted.");
            } catch (InvalidIdempotencyKeyException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }

            $this->assertSame(0, $this->store()->count($execution->id), 'Nothing was stored for an invalid key.');
            $this->assertSame(0, RememberingStep::$effects);
        }
    }

    public function test_an_unserializable_value_fails_the_step_and_leaves_the_record_pending(): void
    {
        // The side effect happened and its value cannot be kept: the
        // honest state for the next attempt is "unknown", not "never ran".
        RememberingStep::$value = new stdClass;
        $execution = $this->runner()->start(new RememberingWorkflow);

        $this->expectException(UnserializableValueException::class);

        try {
            $this->runner()->execute($execution->id, new RememberingWorkflow);
        } finally {
            $this->assertSame(1, RememberingStep::$effects);
            $this->assertSame(IdempotencyStatus::Pending, $this->record($execution)->status);
        }
    }

    public function test_a_context_built_by_hand_refuses_to_remember(): void
    {
        $context = new WorkflowContext(['a' => 1]);

        $this->expectException(IdempotencyStoreUnavailableException::class);

        $context->remember('charge', fn () => $this->fail('The work must not run silently.'));
    }

    // ------------------------------------------------------------------
    // Retention
    // ------------------------------------------------------------------

    public function test_scrub_clears_values_but_keeps_the_record(): void
    {
        $clock = $this->freezeClock();
        $execution = $this->runner()->run(new RememberingWorkflow);
        $this->age($execution, $clock, days: 40);

        $this->app->make(RetentionService::class)->prune(new RetentionPolicy(scrubAfterDays: 30));

        $record = $this->record($execution);
        $this->assertSame(IdempotencyStatus::Settled, $record->status, 'The record still keeps the work from running again.');
        $this->assertNull($record->value, 'The answer is payload, and is gone.');
    }

    public function test_scrub_never_touches_a_failed_executions_records(): void
    {
        // A failed execution is re-executable; scrubbing its remembered
        // values would re-run side effects the record says happened.
        $clock = $this->freezeClock();
        RememberingStep::$failAfterWorkTimes = 1;
        $execution = $this->startAndFail();
        $this->age($execution, $clock, days: 40);

        $this->app->make(RetentionService::class)->prune(new RetentionPolicy(scrubAfterDays: 30));

        $this->assertSame('CH-1', $this->record($execution)->value);
    }

    public function test_delete_removes_the_records_with_the_execution(): void
    {
        $clock = $this->freezeClock();
        $execution = $this->runner()->run(new RememberingWorkflow);
        $this->age($execution, $clock, days: 400);

        $this->app->make(RetentionService::class)->prune(new RetentionPolicy(deleteAfterDays: 365));

        $this->assertSame(0, IdempotencyKeyModel::query()->where('workflow_execution_id', $execution->id)->count());
    }

    // ------------------------------------------------------------------
    // Observability
    // ------------------------------------------------------------------

    public function test_the_show_command_lists_remembered_work_but_never_its_value(): void
    {
        $execution = $this->runner()->run(new RememberingWorkflow);

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id, '--attempts' => true]);

        $this->assertStringContainsString('Remembered work', $output);

        // Only the store's own table: the fixture step also returns the
        // value as its output, which the step table legitimately shows.
        $table = substr($output, (int) strpos($output, 'Remembered work'));
        $this->assertStringContainsString('charge', $table);
        $this->assertStringContainsString('settled', $table);
        $this->assertStringNotContainsString('CH-1', $table, 'Values are results of side effects and may carry anything.');
    }

    public function test_the_show_command_flags_an_unresolved_outcome(): void
    {
        $execution = $this->runAndDieAtTheValueWrite();

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id, '--attempts' => true]);

        $this->assertStringContainsString('outcome unknown', $output);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function startAndFail(): WorkflowExecution
    {
        $execution = $this->runner()->start(new RememberingWorkflow);

        try {
            $this->runner()->execute($execution->id, new RememberingWorkflow);
            $this->fail('The fixture was meant to fail.');
        } catch (RuntimeException) {
            // The fixture's deliberate failure.
        }

        return $execution;
    }

    /**
     * Run the workflow under a store that dies at the value write: the
     * side effect has happened, the marker is durable, and the value is
     * not — the state a worker crash in that instant leaves behind.
     */
    protected function runAndDieAtTheValueWrite(): WorkflowExecution
    {
        $dying = new class($this->store()) implements IdempotencyStore
        {
            public function __construct(private IdempotencyStore $inner) {}

            public function begin(int $executionId, int $stepPosition, string $key, DateTimeImmutable $at): ?IdempotencyRecord
            {
                return $this->inner->begin($executionId, $stepPosition, $key, $at);
            }

            public function settle(int $executionId, string $key, mixed $value, DateTimeImmutable $at): void
            {
                throw new RuntimeException('worker lost between the side effect and the value write');
            }

            public function fail(int $executionId, string $key, string $errorClass, DateTimeImmutable $at): void
            {
                $this->inner->fail($executionId, $key, $errorClass, $at);
            }

            public function release(int $executionId, string $key): void
            {
                $this->inner->release($executionId, $key);
            }

            public function count(int $executionId): int
            {
                return $this->inner->count($executionId);
            }

            public function records(int $executionId): array
            {
                return $this->inner->records($executionId);
            }
        };

        $options = $this->app->make(RunnerOptions::class);
        $runner = $this->makeRunner(
            idempotency: new IdempotencyResolver($dying, $this->app->make(ClockInterface::class), $options),
        );

        $execution = $runner->start(new RememberingWorkflow);

        try {
            $runner->execute($execution->id, new RememberingWorkflow);
            $this->fail('The dying store was meant to take the worker down.');
        } catch (RuntimeException) {
            // The crash.
        }

        return $execution;
    }

    protected function record(WorkflowExecution $execution): IdempotencyRecord
    {
        $records = $this->store()->records($execution->id);
        $this->assertCount(1, $records, 'Expected exactly one remembered record.');

        return $records[0];
    }

    protected function freezeClock(): FixedClock
    {
        $clock = new FixedClock('2026-11-01 12:00:00');
        $this->app->instance(ClockInterface::class, $clock);
        $this->app->forgetInstance(WorkflowRunner::class);
        $this->app->forgetInstance(RetentionService::class);
        $this->app->forgetInstance(IdempotencyResolver::class);

        return $clock;
    }

    protected function age(WorkflowExecution $execution, FixedClock $clock, int $days): void
    {
        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'finished_at' => $clock->now()->modify("-{$days} days"),
        ]);
    }

    protected function store(): IdempotencyStore
    {
        return $this->app->make(IdempotencyStore::class);
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
