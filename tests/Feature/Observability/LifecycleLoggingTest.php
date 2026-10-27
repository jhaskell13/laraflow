<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Observability;

use Laraflow\Events\LifecycleLogger;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\SecretiveWorkflow;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * The optional lifecycle log: one record per event, off by default.
 */
class LifecycleLoggingTest extends TestCase
{
    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);
    }

    public function test_nothing_is_logged_by_default(): void
    {
        $this->runner()->run(new TwoStepWorkflow);

        // An application already listening to these events should not
        // also pay for a log line per step.
        $this->assertSame([], $this->lifecycleRecords());
    }

    public function test_enabling_it_logs_every_lifecycle_event(): void
    {
        $this->enableLifecycleLogging();

        $this->runner()->run(new TwoStepWorkflow);

        $messages = array_column($this->lifecycleRecords(), 'message');

        $this->assertContains('Laraflow: WorkflowExecutionStarted', $messages);
        $this->assertContains('Laraflow: WorkflowStepStarted', $messages);
        $this->assertContains('Laraflow: WorkflowStepCompleted', $messages);
        $this->assertContains('Laraflow: WorkflowExecutionCompleted', $messages);
    }

    public function test_a_record_carries_the_event_s_own_metadata(): void
    {
        $this->enableLifecycleLogging();

        $this->runner()->run(new TwoStepWorkflow, [], new ExecutionOptions('req-abc123', 'acme'));

        $record = $this->recordFor('WorkflowExecutionCompleted');

        $this->assertSame(1, $record['context']['executionId']);
        $this->assertSame(TwoStepWorkflow::class, $record['context']['workflow']);
        $this->assertSame('req-abc123', $record['context']['correlationId']);
        $this->assertSame('acme', $record['context']['tenantId']);
        $this->assertArrayHasKey('durationMs', $record['context']);

        // Datetimes are formatted, not handed to the formatter as
        // objects it would render inconsistently.
        $this->assertIsString($record['context']['completedAt']);
    }

    public function test_null_properties_are_dropped_from_the_record(): void
    {
        $this->enableLifecycleLogging();

        $this->runner()->run(new TwoStepWorkflow);

        $record = $this->recordFor('WorkflowExecutionCompleted');

        $this->assertArrayNotHasKey('correlationId', $record['context']);
        $this->assertArrayNotHasKey('tenantId', $record['context']);
    }

    public function test_a_terminal_failure_logs_at_error_and_a_failed_attempt_at_warning(): void
    {
        $this->enableLifecycleLogging();
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        // Alerting guidance is to page on the execution failing, not on
        // every failed attempt; the levels say the same thing.
        $this->assertSame('error', $this->recordFor('WorkflowExecutionFailed')['level']);
        $this->assertSame('warning', $this->recordFor('WorkflowStepFailed')['level']);
    }

    public function test_the_log_never_carries_input_output_or_context(): void
    {
        $this->enableLifecycleLogging();

        $this->runner()->run(new SecretiveWorkflow, ['secret' => 'tok_SENTINEL123']);

        $encoded = json_encode($this->lifecycleRecords());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(
            'tok_SENTINEL123',
            $encoded,
            'Events carry identifiers and metadata only, so neither can the log built from them.',
        );
    }

    public function test_an_event_added_later_is_logged_without_changing_the_logger(): void
    {
        $this->enableLifecycleLogging();

        // Registered as a wildcard on the namespace, so this stands in
        // for any event class a later version introduces.
        $this->app->make('events')->dispatch('Laraflow\Events\SomeFutureEvent', [new stdClass]);

        $this->assertNotSame([], array_filter(
            $this->lifecycleRecords(),
            fn (array $record): bool => str_contains($record['message'], 'stdClass'),
        ));
    }

    protected function enableLifecycleLogging(): void
    {
        config(['laraflow.logging.lifecycle' => true]);

        $this->app->make('events')->listen(
            'Laraflow\Events\*',
            [LifecycleLogger::class, 'handle'],
        );
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    protected function lifecycleRecords(): array
    {
        return array_values(array_filter(
            $this->logger->records,
            fn (array $record): bool => str_starts_with($record['message'], 'Laraflow: '),
        ));
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    protected function recordFor(string $event): array
    {
        foreach ($this->lifecycleRecords() as $record) {
            if ($record['message'] === "Laraflow: {$event}") {
                return $record;
            }
        }

        $this->fail("No log record for {$event}.");
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
