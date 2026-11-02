<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Queue;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * `laraflow:start` is the command the install story runs a generated
 * workflow with, so what it accepts by name, what it does with input,
 * and how it reports each outcome are held here.
 */
class StartCommandTest extends TestCase
{
    use RunsArtisanCommands;

    /**
     * The class a bare name resolves to lives where make:workflow
     * writes. An alias stands in for a generated file: class_exists(),
     * is_subclass_of(), and the container all honour it.
     */
    private const ALIASED = 'App\\Workflows\\AliasedForStart';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(self::ALIASED, false)) {
            class_alias(TwoStepWorkflow::class, self::ALIASED);
        }
    }

    public function test_it_starts_a_workflow_by_class_name_with_json_input_and_runs_it_inline(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
            '--input' => '{"order_id": 42, "items": ["a", "b"]}',
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Starting '.TwoStepWorkflow::class, $output);
        $this->assertStringContainsString('StepOne', $output);
        $this->assertStringContainsString('StepTwo', $output);
        $this->assertStringContainsString('laraflow:show 1', $output);

        $execution = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Completed->value, $execution->status);
        $this->assertSame(TwoStepWorkflow::class, $execution->workflow);
        // assertEquals, not assertSame: MySQL stores JSON object keys in
        // its own order, and the input is held to its value, not to the
        // order the engine kept it in (as the store contract is).
        $this->assertEquals(['order_id' => 42, 'items' => ['a', 'b']], $execution->input);
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
    }

    public function test_a_bare_name_resolves_under_the_workflows_namespace_and_slashes_are_separators(): void
    {
        foreach (['AliasedForStart', 'App/Workflows/AliasedForStart', '\\App\\Workflows\\AliasedForStart'] as $spelling) {
            ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', ['workflow' => $spelling]);

            $this->assertSame(CommandStatus::SUCCESS, $status, "[{$spelling}] names the aliased workflow: {$output}");
        }

        $this->assertSame(3, WorkflowExecutionModel::query()->count());
        // An alias resolves to the class it stands for, and that is the
        // name recorded — the one the worker will need to find again.
        $this->assertSame(
            [TwoStepWorkflow::class],
            WorkflowExecutionModel::query()->distinct()->pluck('workflow')->all(),
        );
    }

    public function test_a_name_that_is_not_a_class_is_refused_before_anything_is_written(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', ['workflow' => 'NoSuchWorkflow']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('No workflow class named [NoSuchWorkflow]', $output);
        $this->assertStringContainsString('App\Workflows\NoSuchWorkflow', $output, 'Says where else it looked.');
        $this->assertStringContainsString('make:workflow', $output);
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_a_class_that_is_not_a_workflow_is_refused_by_name(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', ['workflow' => WorkflowRunner::class]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('['.WorkflowRunner::class.'] is not a workflow', $output);
        $this->assertStringContainsString(Workflow::class, $output);
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_input_that_is_not_a_json_object_is_refused(): void
    {
        // Pairs rather than a map: PHP would turn the key '42' into an
        // integer, and the console only ever hands a command strings.
        foreach ([['not json', 'valid JSON'], ['[1, 2]', 'a list'], ['"text"', 'string'], ['42', 'int']] as [$input, $complaint]) {
            ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
                'workflow' => TwoStepWorkflow::class,
                '--input' => $input,
            ]);

            $this->assertSame(CommandStatus::FAILURE, $status, "[{$input}] is refused.");
            $this->assertStringContainsString('--input', $output);
            $this->assertStringContainsString($complaint, $output, "[{$input}] is refused for the right reason.");
        }

        $this->assertSame(0, WorkflowExecutionModel::query()->count(), 'Nothing was persisted.');
    }

    public function test_an_empty_input_option_means_no_input(): void
    {
        foreach (['', '{}', '[]'] as $input) {
            ['status' => $status] = $this->callCommand('laraflow:start', [
                'workflow' => TwoStepWorkflow::class,
                '--input' => $input,
            ]);

            $this->assertSame(CommandStatus::SUCCESS, $status, "[{$input}] is no input.");
        }

        foreach (WorkflowExecutionModel::query()->get() as $execution) {
            $this->assertSame([], $execution->input);
        }
    }

    public function test_correlation_and_tenant_are_recorded_and_reach_the_steps(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
            '--correlation' => 'req-77',
            '--tenant' => 'acme',
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('req-77', $output);
        $this->assertStringContainsString('acme', $output);

        $execution = WorkflowExecutionModel::query()->sole();

        $this->assertSame('req-77', $execution->correlation_id);
        $this->assertSame('acme', $execution->tenant_id);
        $this->assertSame('req-77', StepOne::$seenCorrelationId);
        $this->assertSame('acme', StepOne::$seenTenantId);
    }

    public function test_an_invalid_identifier_is_refused_with_the_contracts_message(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
            '--tenant' => str_repeat('x', 256),
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('tenantId', $output);
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_a_step_failure_is_persisted_and_the_recovery_command_is_printed(): void
    {
        StepTwo::$failTimes = 1;

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', ['workflow' => TwoStepWorkflow::class]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('step two blew up', $output);
        $this->assertStringContainsString('laraflow:execute 1', $output);

        $execution = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Failed->value, $execution->status);
        $this->assertNull($execution->claim_token, 'The claim is released for recovery.');
        $this->assertSame('from-step-one', $execution->context['one'] ?? null, 'The first step is checkpointed.');
    }

    public function test_a_workflow_that_parks_on_a_wait_is_reported_as_waiting_not_done(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', ['workflow' => ApprovalWorkflow::class]);

        $this->assertSame(CommandStatus::SUCCESS, $status, 'A wait is not a failure.');
        $this->assertStringContainsString('parked the execution on a wait', $output);
        $this->assertStringContainsString('laraflow:show 1', $output);
        $this->assertStringNotContainsString('Done.', $output);

        $this->assertSame(ExecutionStatus::Waiting->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_the_queue_flag_creates_the_execution_and_dispatches_it(): void
    {
        Queue::fake();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
            '--input' => '{"order_id": 7}',
            '--queue' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Queued as execution 1', $output);
        $this->assertStringContainsString('queue:work', $output);

        $execution = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Pending->value, $execution->status);
        $this->assertSame(['order_id' => 7], $execution->input);
        $this->assertSame(0, StepOne::$runs, 'Nothing ran in this process.');

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );
    }

    public function test_queue_with_a_sync_connection_reports_an_inline_step_failure_gracefully(): void
    {
        // No Queue::fake(): the default sync connection runs the job
        // inside the dispatch call, so the failure surfaces here.
        StepTwo::$failTimes = 1;

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
            '--queue' => true,
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('ran the job in this process', $output);
        $this->assertStringContainsString('laraflow:execute 1', $output);
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
    }

    /**
     * The Phase C audit's first finding. Under the sync driver the job
     * runs inside the dispatch call, so by the time --queue returns the
     * work is already done; telling the user to start a worker is how
     * the result — and a failure — goes unseen.
     */
    public function test_queue_on_a_sync_connection_reports_what_actually_happened_not_queued(): void
    {
        // No Queue::fake(): the sync connection runs the job here.
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
            '--queue' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('ran the job in this process', $output);
        $this->assertStringContainsString('Execution completed', $output);
        $this->assertStringNotContainsString('Queued as execution', $output);
        $this->assertStringNotContainsString('queue:work', $output, 'No worker is needed for work already done.');

        $this->assertSame(ExecutionStatus::Completed->value, WorkflowExecutionModel::query()->sole()->status);
        $this->assertSame(1, StepTwo::$runs, 'The job really did run in this process.');
    }

    /**
     * Same finding, the outcome that is neither done nor failed: the
     * job deliberately does not throw when a step parks the execution.
     */
    public function test_queue_on_a_sync_connection_reports_a_wait_rather_than_queued(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => ApprovalWorkflow::class,
            '--queue' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('parked the execution on a wait', $output);
        $this->assertStringNotContainsString('Queued as execution', $output);

        $this->assertSame(ExecutionStatus::Waiting->value, WorkflowExecutionModel::query()->sole()->status);
    }

    /**
     * The audit's fourth finding: the runtime refusing the run is not a
     * step failure, and must not be reported as one — nothing failed
     * and there is nothing to recover.
     */
    public function test_a_runtime_refusal_is_not_reported_as_a_step_failure(): void
    {
        // Another worker claims the execution between start() and
        // execute(), the window a concurrent recover scan really opens.
        StepOne::$throw = new ClaimLostException('Execution [1] claim was lost.');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('claim was lost', $output);
        $this->assertStringNotContainsString('A step failed', $output);
        $this->assertStringNotContainsString('The failure is persisted', $output);
    }

    /**
     * The audit's second finding: when the push fails, this command is
     * the only thing that knows the id of the execution it just wrote,
     * and the scan that would otherwise find it is off by default.
     */
    public function test_a_push_failure_still_names_the_execution_it_created(): void
    {
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:start', [
            'workflow' => TwoStepWorkflow::class,
            '--queue' => true,
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('could not be queued', $output);
        $this->assertStringContainsString('Connection refused', $output);
        $this->assertStringContainsString('laraflow:execute 1', $output, 'The id it wrote is printed.');

        $this->assertSame(ExecutionStatus::Pending->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_a_workflow_with_no_steps_is_refused_by_the_plan_validation(): void
    {
        // What make:workflow writes before its first step is named.
        $this->assertSame(CommandStatus::FAILURE, $this->callCommand('laraflow:start', ['workflow' => EmptyWorkflowForStart::class])['status']);
        $this->assertStringContainsString('no steps', strtolower($this->callCommand('laraflow:start', ['workflow' => EmptyWorkflowForStart::class])['output']));
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }
}

class EmptyWorkflowForStart extends Workflow
{
    public function steps(): array
    {
        return [];
    }
}
