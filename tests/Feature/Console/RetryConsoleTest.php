<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Date;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * The milestone's operator-visibility exit criterion: the console must
 * say why a step will retry and when, show what every attempt did, mark
 * hung steps, and make running ahead of schedule an explicit act.
 */
class RetryConsoleTest extends TestCase
{
    use RunsArtisanCommands;

    protected function setUp(): void
    {
        parent::setUp();

        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(
            maxAttempts: 3,
            baseDelaySeconds: 3600,
            maxDelaySeconds: 3600,
            jitter: 0.0,
        );
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_execute_reports_a_scheduled_retry_as_success_with_the_due_time(): void
    {
        Date::setTestNow('2026-10-23 16:00:00');
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->start(new PolicyWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:execute',
            ['execution' => (string) $execution->id],
        );

        // A scheduled retry is not an operator failure: the policy owns it.
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('scheduled another attempt for 2026-10-23 17:00:00', $output);
        $this->assertStringContainsString('--now', $output);
        $this->assertStringContainsString('retrying', $output);
        $this->assertStringContainsString('Next retry', $output);
        $this->assertStringContainsString('step two blew up', $output);
    }

    public function test_executing_before_the_due_time_fails_with_the_schedule_and_now_overrides(): void
    {
        Date::setTestNow('2026-10-23 16:00:00');
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
        $id = (string) $execution->id;

        Date::setTestNow('2026-10-23 16:30:00');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => $id]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('not due until [2026-10-23 17:00:00]', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:execute',
            ['execution' => $id, '--now' => true],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Execution completed', $output);
    }

    public function test_show_displays_the_schedule_and_the_full_attempt_history(): void
    {
        Date::setTestNow('2026-10-23 16:00:00');
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);

        ['output' => $output] = $this->callCommand('laraflow:show', [
            'execution' => (string) $execution->id,
            '--attempts' => true,
        ]);

        // Why and when, at the execution and step level.
        $this->assertStringContainsString('Next retry', $output);
        $this->assertStringContainsString('2026-10-23 17:00:00', $output);
        $this->assertStringContainsString('retrying', $output);

        // The history: step one completed, step two failed and scheduled.
        $this->assertStringContainsString('Outcome', $output);
        $this->assertStringContainsString('StepOne', $output);
        $this->assertStringContainsString('completed', $output);
        $this->assertStringContainsString('Scheduled retry', $output);
        $this->assertStringContainsString('step two blew up', $output);
    }

    public function test_show_marks_a_running_step_past_its_deadline_as_overdue(): void
    {
        Date::setTestNow('2026-10-23 16:00:00');

        // A live worker owns the execution; its step overran its deadline.
        $repository = $this->app->make(ExecutionRepository::class);
        $execution = $this->app->make(WorkflowRunner::class)->start(new PolicyWorkflow);
        $execution = $repository->claim($execution->id, 'live-worker', 300);

        $step = $repository->steps($execution)[0];
        $step->markRunning(Date::now()->toImmutable(), Date::now()->addSeconds(30)->toImmutable());
        $repository->saveStep($step);

        Date::setTestNow('2026-10-23 16:05:00');

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);

        $this->assertStringContainsString('(overdue)', $output);
    }

    public function test_show_displays_cancellation_detail(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $execution = $runner->start(new PolicyWorkflow);
        $runner->cancel($execution->id, 'order voided');

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);

        $this->assertStringContainsString('cancelled', $output);
        $this->assertStringContainsString('order voided', $output);
    }

    public function test_list_shows_retrying_and_cancelled_executions(): void
    {
        Date::setTestNow('2026-10-23 16:00:00');
        StepTwo::$failTimes = 1;

        $runner = $this->app->make(WorkflowRunner::class);
        $runner->run(new PolicyWorkflow);

        $cancelled = $runner->start(new PolicyWorkflow);
        $runner->cancel($cancelled->id);

        ['output' => $output] = $this->callCommand('laraflow:list', ['--status' => 'retrying']);
        $this->assertStringContainsString('retrying', $output);

        ['output' => $output] = $this->callCommand('laraflow:list', ['--status' => 'cancelled']);
        $this->assertStringContainsString('cancelled', $output);
    }

    public function test_recover_reports_a_due_retry_with_its_reason_and_due_time(): void
    {
        Date::setTestNow('2026-10-23 16:00:00');
        StepTwo::$failTimes = 1;

        $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);

        Date::setTestNow('2026-10-23 17:30:00');

        ['output' => $output] = $this->callCommand('laraflow:recover', ['--dry-run' => true]);

        $this->assertStringContainsString('retry due', $output);
        $this->assertStringContainsString('2026-10-23 17:00:00', $output, 'Since = when the retry came due.');
        $this->assertStringContainsString('would dispatch', $output);

        // Dry run changed nothing.
        $this->assertNotNull(WorkflowExecutionModel::query()->sole()->next_retry_at);
        $this->assertSame(1, StepTwo::$runs);
    }

    public function test_the_step_table_shows_each_steps_next_retry(): void
    {
        Date::setTestNow('2026-10-23 16:00:00');
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);

        $retryingRow = collect(explode("\n", $output))
            ->first(fn (string $line): bool => str_contains($line, 'StepTwo'));

        $this->assertNotNull($retryingRow);
        $this->assertStringContainsString('2026-10-23 17:00:00', $retryingRow);

        // Confirm the DB agrees (the column is real, not derived).
        $this->assertSame(
            '2026-10-23 17:00:00',
            StepExecutionModel::query()->where('position', 1)->sole()->next_retry_at?->format('Y-m-d H:i:s'),
        );
    }
}
