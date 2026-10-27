<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\PassthroughRedactor;
use Laraflow\Tests\Support\ArrayLogger;
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
