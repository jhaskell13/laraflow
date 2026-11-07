<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\PassthroughRedactor;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\DatabaseErrorStep;
use Laraflow\Tests\Support\LeakyStep;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TokenRedactor;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * One chokepoint: every exception message Laraflow retains — persisted
 * columns, attempt history, log records, failure events — passes
 * through the configured redactor first. The exception the application
 * catches is untouched.
 */
class ErrorRedactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['laraflow.errors.redactor' => TokenRedactor::class]);

        PolicyWorkflow::$steps = [StepOne::class, LeakyStep::class];
    }

    public function test_a_terminal_failure_is_redacted_in_every_durable_sink(): void
    {
        try {
            $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
            $this->fail('Expected the step exception.');
        } catch (RuntimeException $exception) {
            // What the application catches is not Laraflow's to rewrite.
            $this->assertStringContainsString('tok_SENTINEL123', $exception->getMessage());
        }

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertStringContainsString('[redacted]', (string) $execution->error);
        $this->assertStringNotContainsString('tok_SENTINEL123', (string) $execution->error);
        $this->assertStringContainsString('customer 42', (string) $execution->error, 'Only the secret goes.');

        $step = StepExecutionModel::query()->where('position', 1)->sole();
        $this->assertStringNotContainsString('tok_SENTINEL123', (string) $step->error);

        $attempt = StepAttemptModel::query()->where('step_execution_id', $step->id)->sole();
        $this->assertStringNotContainsString('tok_SENTINEL123', (string) $attempt->error);
    }

    public function test_failure_events_carry_only_the_redacted_message(): void
    {
        Event::fake([WorkflowStepFailed::class, WorkflowExecutionFailed::class]);

        try {
            $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        Event::assertDispatched(
            WorkflowStepFailed::class,
            fn (WorkflowStepFailed $event): bool => str_contains((string) $event->error, '[redacted]')
                && ! str_contains((string) $event->error, 'tok_SENTINEL123'),
        );
        Event::assertDispatched(
            WorkflowExecutionFailed::class,
            fn (WorkflowExecutionFailed $event): bool => ! str_contains((string) $event->error, 'tok_SENTINEL123'),
        );
    }

    public function test_a_scheduled_retry_is_redacted_in_state_history_and_event(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 10, jitter: 0.0);

        Event::fake([WorkflowStepRetryScheduled::class]);

        $execution = $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);

        $this->assertTrue($execution->isRetrying());
        $this->assertStringNotContainsString('tok_SENTINEL123', (string) $execution->error);
        $this->assertStringNotContainsString(
            'tok_SENTINEL123',
            (string) StepAttemptModel::query()->where('status', 'failed')->sole()->error,
        );

        Event::assertDispatched(
            WorkflowStepRetryScheduled::class,
            fn (WorkflowStepRetryScheduled $event): bool => ! str_contains((string) $event->error, 'tok_SENTINEL123'),
        );
    }

    public function test_the_failed_job_log_is_redacted(): void
    {
        $logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $logger);

        (new ExecuteWorkflowJob(1))->failed(
            new RuntimeException('token tok_SENTINEL123 leaked'),
        );

        $this->assertTrue($logger->hasRecordContaining('error', 'execution job failed'));

        $logged = (string) $logger->records[0]['context']['exception'];
        $this->assertStringContainsString('[redacted]', $logged);
        $this->assertStringNotContainsString('tok_SENTINEL123', $logged);
    }

    /**
     * Laravel formats a QueryException's message with every bound value
     * substituted into the SQL, so a database error inside a step — or
     * inside a checkpoint — carries the workflow's data in its message.
     * Found by the soak suite on its first run (Milestone 12): the
     * bindings are stripped before any redactor sees the message, so
     * the protection does not depend on one being configured.
     */
    public function test_a_database_errors_bound_values_never_reach_a_durable_sink(): void
    {
        config(['laraflow.errors.redactor' => null]);
        $this->app->forgetInstance(ErrorRedactor::class);
        PolicyWorkflow::$steps = [StepOne::class, DatabaseErrorStep::class];

        Event::fake([WorkflowStepFailed::class, WorkflowExecutionFailed::class]);

        try {
            $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
            $this->fail('Expected the step exception.');
        } catch (QueryException $exception) {
            // The application catches the exception Laravel built, data
            // and all; what Laraflow retains is another matter.
            $this->assertStringContainsString('customer 42', $exception->getMessage());
        }

        $retained = [
            'execution' => (string) WorkflowExecutionModel::query()->sole()->error,
            'step' => (string) StepExecutionModel::query()->where('position', 1)->sole()->error,
            'attempt' => (string) StepAttemptModel::query()->where('status', 'failed')->sole()->error,
        ];

        foreach ($retained as $sink => $message) {
            $this->assertStringContainsString('database is locked', $message, "The driver's error is kept ({$sink}).");
            $this->assertStringContainsString('insert into "ledger" ("note", "amount") values (?, ?)', $message, "The statement is kept, with placeholders ({$sink}).");
            $this->assertStringNotContainsString('customer 42', $message, "No bound value survives ({$sink}).");
            $this->assertStringNotContainsString('1999', $message, "No bound value survives ({$sink}).");
        }

        Event::assertDispatched(
            WorkflowStepFailed::class,
            fn (WorkflowStepFailed $event): bool => ! str_contains((string) $event->error, 'customer 42')
                && str_contains((string) $event->error, 'values (?, ?)'),
        );
        Event::assertDispatched(
            WorkflowExecutionFailed::class,
            fn (WorkflowExecutionFailed $event): bool => ! str_contains((string) $event->error, 'customer 42'),
        );
    }

    /**
     * The shape the soak produced: Laravel wraps a concurrency failure
     * in a DeadlockException built from the query exception's message,
     * so the values are in an exception that is not a QueryException.
     */
    public function test_a_deadlocks_bound_values_never_reach_a_durable_sink_either(): void
    {
        config(['laraflow.errors.redactor' => null]);
        $this->app->forgetInstance(ErrorRedactor::class);
        PolicyWorkflow::$steps = [StepOne::class, DatabaseErrorStep::class];

        try {
            $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow, ['deadlock' => true]);
            $this->fail('Expected the step exception.');
        } catch (DeadlockException $exception) {
            $this->assertStringContainsString('customer 42', $exception->getMessage());
        }

        $retained = [
            'execution' => (string) WorkflowExecutionModel::query()->sole()->error,
            'step' => (string) StepExecutionModel::query()->where('position', 1)->sole()->error,
            'attempt' => (string) StepAttemptModel::query()->where('status', 'failed')->sole()->error,
        ];

        foreach ($retained as $sink => $message) {
            $this->assertStringContainsString('values (?, ?)', $message, "The statement is kept, with placeholders ({$sink}).");
            $this->assertStringNotContainsString('customer 42', $message, "No bound value survives ({$sink}).");
        }
    }

    public function test_the_failed_job_log_strips_database_bindings_too(): void
    {
        $logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $logger);

        (new ExecuteWorkflowJob(1))->failed(new QueryException(
            'testing',
            'update "accounts" set "secret" = ? where "id" = ?',
            ['tok_SENTINEL123', 7],
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
        ));

        $logged = (string) $logger->records[0]['context']['exception'];
        $this->assertStringContainsString('set "secret" = ? where "id" = ?', $logged);
        $this->assertStringNotContainsString('tok_SENTINEL123', $logged);
    }

    public function test_a_misconfigured_redactor_fails_loudly_instead_of_leaking(): void
    {
        config(['laraflow.errors.redactor' => stdClass::class]);
        $this->app->forgetInstance(ErrorRedactor::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('laraflow.errors.redactor');

        $this->app->make(ErrorRedactor::class);
    }

    public function test_without_a_redactor_messages_pass_through_unchanged(): void
    {
        config(['laraflow.errors.redactor' => null]);
        $this->app->forgetInstance(ErrorRedactor::class);
        $this->app->forgetInstance(WorkflowRunner::class);

        $this->assertInstanceOf(PassthroughRedactor::class, $this->app->make(ErrorRedactor::class));

        StepTwo::$failTimes = 1;

        try {
            $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame('step two blew up', WorkflowExecutionModel::query()->sole()->error);
    }

    public function test_a_blank_env_value_means_disabled_not_broken(): void
    {
        config(['laraflow.errors.redactor' => '']);
        $this->app->forgetInstance(ErrorRedactor::class);

        $this->assertInstanceOf(PassthroughRedactor::class, $this->app->make(ErrorRedactor::class));
    }
}
