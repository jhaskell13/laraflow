<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\NightlyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Models\ScheduleModel;
use Laraflow\Workflow\Models\ScheduleTickModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * docs/schedules.md, the operator's half: the tick run's report and
 * exit codes, the listing with pause, resume, and forget, the orphan
 * and stalled conditions, and the origin line in laraflow:show.
 */
class ScheduleCommandsTest extends TestCase
{
    use RunsArtisanCommands;

    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-11-03 14:30:00');
        $this->app->instance(ClockInterface::class, $this->clock);

        config()->set('laraflow.schedules.workflows', [NightlyWorkflow::class]);
    }

    public function test_the_tick_run_reports_what_it_did_per_schedule(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('NightlyWorkflow', $output);
        $this->assertStringContainsString('not due', $output);

        $this->clock->set('2026-11-04 02:00:30');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('started', $output);
        $this->assertStringContainsString('2026-11-04 02:00', $output);
        $this->assertStringContainsString('1 tick(s) claimed', $output);
        $this->assertSame(1, ScheduleTickModel::query()->count());
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
    }

    public function test_dry_run_names_the_tick_it_would_claim_and_claims_none(): void
    {
        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-04 02:00:30');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule', ['--dry-run' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('would start', $output);
        $this->assertStringContainsString('nothing was claimed', $output);
        $this->assertSame(0, ScheduleTickModel::query()->count());
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_a_broken_declaration_is_a_failed_row_and_a_non_zero_exit_even_dry(): void
    {
        config()->set('laraflow.schedules.workflows', ['App\\Workflows\\Vanished', NightlyWorkflow::class]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule', ['--dry-run' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('Vanished', $output);
        $this->assertStringContainsString('1 failed', $output);
        $this->assertStringContainsString('no such class exists', $output);
    }

    public function test_a_sync_run_whose_step_fails_exits_non_zero_like_recover_does(): void
    {
        NightlyWorkflow::$steps = [\Laraflow\Tests\Support\StepTwo::class];
        \Laraflow\Tests\Support\StepTwo::$failTimes = 10;

        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-04 02:00:30');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule', ['--sync' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status, 'An inline delivery failure needs a person.');
        $this->assertStringContainsString('failed', $output);
        $this->assertSame(1, ScheduleTickModel::query()->count(), 'The tick itself succeeded; the failure is on the execution.');
    }

    public function test_a_malformed_workflows_list_is_one_json_refusal_not_a_stack_trace(): void
    {
        config()->set('laraflow.schedules.workflows', 'not-a-list');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule', ['--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['ok']);
        $this->assertStringContainsString('must be a list', $document['error']);
    }

    public function test_the_kill_switch_and_an_empty_registry_are_quiet_successes(): void
    {
        config()->set('laraflow.schedules.enabled', false);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('disabled', $output);

        config()->set('laraflow.schedules.enabled', true);
        config()->set('laraflow.schedules.workflows', []);
        $this->app->forgetInstance(\Laraflow\Workflow\Scheduling\SchedulerOptions::class);
        $this->app->forgetInstance(\Laraflow\Workflow\Scheduling\Scheduler::class);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('No schedules are registered', $output);
    }

    public function test_the_listing_pauses_and_resumes_a_schedule(): void
    {
        $this->callCommand('laraflow:schedule');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('NightlyWorkflow', $output);
        $this->assertStringContainsString('0 2 * * *', $output);
        $this->assertStringContainsString('ok', $output);

        ['output' => $output] = $this->callCommand('laraflow:schedules', ['--pause' => NightlyWorkflow::class]);
        $this->assertStringContainsString('names no ticks until resumed', $output);

        ['output' => $json] = $this->callCommand('laraflow:schedules', ['--json' => true]);
        $this->assertSame('paused', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['schedules'][0]['state'], 'The state cell, not the action chatter.');

        $this->clock->set('2026-11-04 02:00:30');
        ['output' => $output] = $this->callCommand('laraflow:schedule');
        $this->assertStringContainsString('paused', $output);
        $this->assertSame(0, ScheduleTickModel::query()->count());

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--resume' => NightlyWorkflow::class]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('never caught up', $output);

        ['output' => $output] = $this->callCommand('laraflow:schedule');
        $this->assertStringContainsString('not due', $output);
    }

    public function test_forget_is_for_orphans_whose_history_is_not_wanted(): void
    {
        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-04 02:00:30');
        $this->callCommand('laraflow:schedule');

        // Live declaration: refused, and nothing is deleted.
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--forget' => NightlyWorkflow::class]);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('live declaration', $output);
        $this->assertSame(1, ScheduleModel::query()->count());

        // Unlisted: the row is an orphan, the listing says so loudly.
        config()->set('laraflow.schedules.workflows', []);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules');
        $this->assertSame(CommandStatus::FAILURE, $status);
        ['output' => $json] = $this->callCommand('laraflow:schedules', ['--json' => true]);
        $this->assertSame('orphaned', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['schedules'][0]['state']);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--forget' => NightlyWorkflow::class]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('forgotten', $output);
        $this->assertSame(0, ScheduleModel::query()->count());
        $this->assertSame(0, ScheduleTickModel::query()->count(), 'The history went with the row the operator chose to forget.');
        $this->assertSame(1, WorkflowExecutionModel::query()->count(), 'Executions the ticks started are untouched, as ever.');
    }

    public function test_a_stalled_schedule_fails_the_listing_and_the_summary(): void
    {
        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-04 02:00:30');
        $this->callCommand('laraflow:schedule');

        // Three due instants stand unclaimed: the scheduler stopped.
        $this->clock->set('2026-11-07 02:01:00');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules');
        $this->assertSame(CommandStatus::FAILURE, $status);
        ['output' => $json] = $this->callCommand('laraflow:schedules', ['--json' => true]);
        $this->assertSame('stalled', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['schedules'][0]['state']);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('stalled', $output);
        $this->assertStringContainsString('laraflow:schedules', $output);
    }

    public function test_the_summary_does_not_sleep_through_a_stalled_schedule_with_no_executions(): void
    {
        // A dead scheduler that never claimed a tick leaves exactly
        // this: a registered schedule, an empty executions table.
        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-07 02:01:00');

        $this->assertSame(0, WorkflowExecutionModel::query()->count());

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('stalled', $output);
        $this->assertStringNotContainsString('No executions found', $output);
    }

    public function test_show_names_the_tick_that_started_an_execution(): void
    {
        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-04 02:00:30');
        $this->callCommand('laraflow:schedule');

        // Down three days, so the next tick carries a missed count the
        // origin line should repeat.
        $this->clock->set('2026-11-07 02:00:30');
        $this->callCommand('laraflow:schedule');

        $scheduled = WorkflowExecutionModel::query()->orderByDesc('id')->firstOrFail()->id;

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $scheduled]);
        $this->assertStringContainsString('Started by', $output);
        $this->assertStringContainsString('schedule tick 2026-11-07 02:00', $output);
        $this->assertStringContainsString('2 missed before it', $output);

        // An execution nothing scheduled carries no origin line.
        $manual = $this->app->make(WorkflowRunner::class)->run(new NightlyWorkflow);

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $manual->id]);
        $this->assertStringNotContainsString('Started by', $output);
    }

    public function test_the_listing_actions_are_one_decision_each_and_refuse_unknown_schedules(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', [
            '--pause' => NightlyWorkflow::class,
            '--resume' => NightlyWorkflow::class,
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('one of --pause, --resume, or --forget', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules', ['--pause' => 'App\\Nope']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('No schedule exists for [App\\Nope]', $output);
    }

    public function test_an_empty_installation_explains_how_a_schedule_comes_to_exist(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedules');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('No schedules exist yet', $output);
    }

    public function test_tick_history_is_pruned_on_its_own_bound_and_executions_are_untouched(): void
    {
        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-04 02:00:30');
        $this->callCommand('laraflow:schedule');
        $this->clock->set('2026-11-10 02:00:30');
        $this->callCommand('laraflow:schedule');

        $this->assertSame(2, ScheduleTickModel::query()->count());

        // Nothing configured: the ticks bound alone enables the command.
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', ['--prune-ticks-after' => '2', '--dry-run' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('ticks pruned', $output);
        $this->assertSame(2, ScheduleTickModel::query()->count(), 'A dry run removes nothing.');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:prune', ['--prune-ticks-after' => '2', '--force' => true, '--json' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $document['report']['tick_rows'], 'The old tick went; the fresh one stays.');

        $this->assertSame(1, ScheduleTickModel::query()->count());
        $this->assertSame(2, WorkflowExecutionModel::query()->count(), 'Pruning a tick never touches an execution.');
    }

    protected function schedules(): ScheduleRepository
    {
        return $this->app->make(ScheduleRepository::class);
    }
}
