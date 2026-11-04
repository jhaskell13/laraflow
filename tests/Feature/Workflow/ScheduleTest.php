<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowScheduleSkipped;
use Laraflow\Events\WorkflowScheduleTicked;
use Laraflow\Exceptions\Workflow\TickAlreadyClaimedException;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\NightlyWorkflow;
use Laraflow\Tests\Support\OneStepWorkflow;
use Laraflow\Tests\Support\PauseStep;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\MissedTickPolicy;
use Laraflow\Workflow\Models\ScheduleTickModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Scheduling\ScheduleAction;
use Laraflow\Workflow\Scheduling\Scheduler;
use Laraflow\Workflow\Scheduling\ScheduleRegistry;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\Scheduling\SchedulerOptions;
use Laraflow\Workflow\Scheduling\SchedulerRun;
use Laraflow\Workflow\Scheduling\TickOutcome;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;

/**
 * docs/schedules.md, enforced: one execution per tick however many
 * schedulers run, the missed-tick policies, overlap suppression, the
 * lost-dispatch heal, pause and resume, orphans, and the events.
 */
class ScheduleTest extends TestCase
{
    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-11-03 14:30:00');
        $this->app->instance(ClockInterface::class, $this->clock);

        config()->set('laraflow.schedules.workflows', [NightlyWorkflow::class]);
    }

    public function test_a_new_schedule_registers_and_never_backfills(): void
    {
        $run = $this->scheduler()->run();

        $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($run));
        $this->assertSame(0, ScheduleTickModel::query()->count());
        $this->assertSame(0, WorkflowExecutionModel::query()->count());

        $row = $this->schedules()->find(NightlyWorkflow::class);
        $this->assertNotNull($row, 'The first run synchronised the declaration to a row.');
        $this->assertSame('0 2 * * *', $row->cron);
        $this->assertSame('2026-11-03 14:30:00', $row->lastTickAt?->format('Y-m-d H:i:s'), 'Nothing before registration is ever due.');
    }

    public function test_one_tick_is_one_execution_however_many_runs_race_it(): void
    {
        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-04 02:00:30');

        $first = $scheduler->run();
        $second = $scheduler->run();
        $third = $scheduler->run();

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($first));
        $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($second));
        $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($third));

        $this->assertSame(1, ScheduleTickModel::query()->count(), 'One tick row, however many runs read the minute.');
        $this->assertSame(1, WorkflowExecutionModel::query()->count());
        $this->assertSame(1, StepOne::$runs, 'The sync queue ran the one execution exactly once.');

        $execution = $this->executions()->find($this->startedId($first));
        $this->assertSame('schedule:NightlyWorkflow@2026-11-04T02:00Z', $execution->correlationId);
        $this->assertTrue($execution->isCompleted());
    }

    public function test_a_tick_claimed_by_another_scheduler_is_reported_taken(): void
    {
        $this->scheduler()->run();
        $this->clock->set('2026-11-04 02:00:30');

        $row = $this->schedules()->find(NightlyWorkflow::class);
        $this->assertNotNull($row);

        // Another scheduler mid-commit: the tick row exists, our read of
        // the high-water mark predates it.
        ScheduleTickModel::query()->create([
            'schedule_id' => $row->id,
            'tick_at' => new DateTimeImmutable('2026-11-04 02:00:00', new DateTimeZone('UTC')),
            'outcome' => TickOutcome::Started->value,
            'created_at' => $this->clock->now(),
        ]);

        $run = $this->scheduler()->run();

        $this->assertSame([ScheduleAction::TAKEN], $this->outcomes($run));
        $this->assertSame(0, WorkflowExecutionModel::query()->count(), 'The loser started nothing.');
        $this->assertFalse($run->needsAttention(), 'Losing the race is the designed outcome, not a fault.');
    }

    public function test_skip_runs_the_latest_tick_and_counts_the_gap_on_its_row(): void
    {
        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-04 02:00:30');
        $scheduler->run();

        // The scheduler is down for three days.
        $this->clock->set('2026-11-07 02:00:30');
        $run = $scheduler->run();

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($run));
        $this->assertSame(2, WorkflowExecutionModel::query()->count(), 'One per fired tick; the gap ran nothing.');

        $tick = ScheduleTickModel::query()->orderByDesc('tick_at')->firstOrFail();
        $this->assertSame('2026-11-07 02:00:00', $tick->tick_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $tick->missed, 'An outage is a count on one row, not a row per instant.');
        $this->assertSame('2026-11-05 02:00:00', $tick->missed_from?->format('Y-m-d H:i:s'));
    }

    public function test_catch_up_runs_missed_ticks_oldest_first_each_told_its_own_tick(): void
    {
        NightlyWorkflow::$policy = MissedTickPolicy::CatchUp;
        NightlyWorkflow::$input = fn (DateTimeImmutable $tick): array => [
            'day' => $tick->modify('-1 day')->format('Y-m-d'),
        ];
        config()->set('laraflow.schedules.catch_up_window', 7 * 86400);

        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-07 02:00:30');
        $run = $scheduler->run();

        $this->assertSame(
            [ScheduleAction::CAUGHT_UP, ScheduleAction::CAUGHT_UP, ScheduleAction::CAUGHT_UP, ScheduleAction::STARTED],
            $this->outcomes($run),
            'Oldest first, and the current instant is started rather than caught up.',
        );

        $days = array_map(
            fn (ScheduleAction $action): mixed => $this->executions()->find((int) $action->executionId)->input['day'] ?? null,
            $run->actions,
        );

        $this->assertSame(['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'], $days, 'Each execution processes its own night, not today four times.');
    }

    public function test_catch_up_is_bounded_per_run_and_the_next_run_resumes(): void
    {
        NightlyWorkflow::$policy = MissedTickPolicy::CatchUp;
        config()->set('laraflow.schedules.catch_up_window', 7 * 86400);
        config()->set('laraflow.schedules.max_catch_up', 2);

        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-07 02:00:30');

        $first = $scheduler->run();
        $this->assertSame([ScheduleAction::CAUGHT_UP, ScheduleAction::CAUGHT_UP], $this->outcomes($first), 'The bound holds; the remainder waits.');

        $second = $scheduler->run();
        $this->assertSame([ScheduleAction::CAUGHT_UP, ScheduleAction::STARTED], $this->outcomes($second), 'The next run resumes exactly where the bound stopped.');

        $this->assertSame(4, WorkflowExecutionModel::query()->count());
    }

    public function test_catch_up_reaches_no_further_back_than_its_window(): void
    {
        NightlyWorkflow::$policy = MissedTickPolicy::CatchUp;

        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-07 02:00:30');
        $run = $scheduler->run();

        // The default window is a day: 11-04 through 11-06 are out of
        // reach, counted and skipped; the present tick still runs.
        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($run));

        $tick = ScheduleTickModel::query()->firstOrFail();
        $this->assertSame(3, $tick->missed);
        $this->assertSame('2026-11-04 02:00:00', $tick->missed_from?->format('Y-m-d H:i:s'));
    }

    public function test_without_overlapping_claims_the_tick_and_names_what_it_declined_to_run_beside(): void
    {
        NightlyWorkflow::$withoutOverlapping = true;
        NightlyWorkflow::$steps = [PauseStep::class];

        Event::fake([WorkflowScheduleSkipped::class]);

        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-04 02:00:30');
        $first = $scheduler->run();
        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($first));

        $parked = $this->startedId($first);
        $this->assertTrue($this->executions()->find($parked)->isWaiting(), 'The first tick is parked, not finished.');

        $this->clock->set('2026-11-05 02:00:30');
        $second = $scheduler->run();

        $this->assertSame([ScheduleAction::SKIPPED_OVERLAP], $this->outcomes($second));
        $this->assertSame($parked, $second->actions[0]->overlappedExecutionId);
        $this->assertSame(1, WorkflowExecutionModel::query()->count(), 'Suppressed means suppressed.');

        $tick = ScheduleTickModel::query()->orderByDesc('tick_at')->firstOrFail();
        $this->assertSame(TickOutcome::SkippedOverlap->value, $tick->outcome);
        $this->assertSame($parked, $tick->overlapped_execution_id);
        $this->assertNull($tick->workflow_execution_id);

        Event::assertDispatched(
            WorkflowScheduleSkipped::class,
            fn (WorkflowScheduleSkipped $event): bool => $event->overlappedExecutionId === $parked
                && $event->workflow === NightlyWorkflow::class,
        );

        // The parked execution finishes (here: cancelled), and the next
        // tick runs again.
        $this->app->make(WorkflowRunner::class)->cancel($parked);

        $this->clock->set('2026-11-06 02:00:30');
        $third = $scheduler->run();

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($third));
    }

    public function test_pruning_ticks_never_breaks_without_overlapping(): void
    {
        // The Milestone 10 audit: pruneTicks() aged by created_at alone,
        // took the latest fired tick of a schedule whose execution was
        // still parked, and the next tick started beside it.
        NightlyWorkflow::$withoutOverlapping = true;
        NightlyWorkflow::$steps = [PauseStep::class];

        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-04 02:00:30');
        $parked = $this->startedId($scheduler->run());

        // Long after, every tick is old enough to prune by age.
        $this->clock->set('2026-12-30 02:00:30');
        $this->assertSame(0, $this->schedules()->countTicksBefore($this->clock->now()));
        $this->assertSame(0, $this->schedules()->pruneTicks($this->clock->now(), 100));

        $this->clock->set('2026-12-31 02:00:30');
        $suppressed = $scheduler->run();
        $this->assertSame([ScheduleAction::SKIPPED_OVERLAP], $this->outcomes($suppressed));
        $this->assertSame($parked, $suppressed->actions[0]->overlappedExecutionId);

        // The parked run finishes; a new tick fires and parks too.
        $this->app->make(WorkflowRunner::class)->cancel($parked);
        $this->clock->set('2027-01-01 02:00:30');
        $latest = $this->startedId($scheduler->run());

        $this->clock->set('2027-03-01 00:00:00');
        $this->assertSame(2, $this->schedules()->countTicksBefore($this->clock->now()));
        $this->assertSame(2, $this->schedules()->pruneTicks($this->clock->now(), 100), 'The finished tick and the skipped one go.');

        $kept = ScheduleTickModel::query()->sole();
        $this->assertSame($latest, $kept->workflow_execution_id, 'The latest fired tick, still unfinished, stays.');

        // Finished, it is still the latest fired tick: kept all the same.
        $this->app->make(WorkflowRunner::class)->cancel($latest);
        $this->assertSame(0, $this->schedules()->pruneTicks($this->clock->now(), 100));
    }

    public function test_a_lost_dispatch_is_healed_and_the_applications_own_pending_work_is_not(): void
    {
        // Real-clock base: Eloquent writes created_at from the real
        // clock, and the heal compares against it.
        $this->clock->set(new DateTimeImmutable('now'));
        NightlyWorkflow::$cron = '0 2 1 1 *';

        $scheduler = $this->scheduler();
        $scheduler->run();

        $row = $this->schedules()->find(NightlyWorkflow::class);
        $this->assertNotNull($row);

        // A tick whose transaction committed and whose push was lost:
        // claimed through the repository, deliberately never dispatched.
        $runner = $this->app->make(WorkflowRunner::class);
        $tick = $this->schedules()->claimTick(
            $row,
            $this->clock->now(),
            TickOutcome::Started,
            fn (): WorkflowExecution => $runner->start(new NightlyWorkflow),
            $this->clock->now(),
        );

        $scheduled = $tick->workflowExecutionId;
        $this->assertNotNull($scheduled);

        // The application's own held-back work, same age, no tick.
        $held = $runner->start(new OneStepWorkflow)->id;

        // Too fresh: nothing is healed yet.
        $this->assertSame([], $this->scheduler()->run()->redispatched);

        // Well past the 300s window: the base is the real clock, and a
        // slow engine container must not eat the margin.
        $this->clock->advance(400);
        $run = $this->scheduler()->run();

        $this->assertSame([$scheduled], $run->redispatched, 'The lost dispatch is re-pushed; the held execution is not.');
        $this->assertTrue($this->executions()->find($scheduled)->isCompleted(), 'The sync driver ran it on the spot.');
        $this->assertTrue($this->executions()->find($held)->isPending(), 'Recovery must never start work the application is holding.');
    }

    public function test_paused_names_no_tick_and_resuming_never_catches_up(): void
    {
        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->schedules()->pause(NightlyWorkflow::class, $this->clock->now());

        $this->clock->set('2026-11-06 02:00:30');
        $run = $scheduler->run();

        $this->assertSame([ScheduleAction::PAUSED], $this->outcomes($run));
        $this->assertSame(0, ScheduleTickModel::query()->count());

        // Resume: the paused days are gone, not queued.
        $this->schedules()->resume(NightlyWorkflow::class, $this->clock->now());

        $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($scheduler->run()));

        $this->clock->set('2026-11-07 02:00:30');
        $after = $scheduler->run();

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($after));
        $this->assertSame(0, ScheduleTickModel::query()->firstOrFail()->missed ?? 0, 'Paused means do not run, not run later.');
    }

    public function test_a_refused_input_rolls_the_tick_back_and_a_later_run_retries(): void
    {
        NightlyWorkflow::$input = fn (): array => ['handle' => fopen('php://memory', 'r')];

        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-04 02:00:30');
        $failed = $scheduler->run();

        $this->assertSame([ScheduleAction::FAILED], $this->outcomes($failed));
        $this->assertTrue($failed->needsAttention());
        $this->assertSame(0, ScheduleTickModel::query()->count(), 'The tick rolled back with the execution it could not start.');
        $this->assertSame(0, WorkflowExecutionModel::query()->count());

        // The code is fixed; the unclaimed tick is still there to claim.
        NightlyWorkflow::$input = null;

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($scheduler->run()));
    }

    public function test_a_broken_declaration_fails_its_row_without_stopping_the_others(): void
    {
        config()->set('laraflow.schedules.workflows', [
            'App\\Workflows\\Vanished',
            OneStepWorkflow::class,
            NightlyWorkflow::class,
        ]);

        $scheduler = $this->scheduler();
        $scheduler->run();
        $this->clock->set('2026-11-04 02:00:30');

        $run = $scheduler->run();

        $this->assertSame(
            [ScheduleAction::FAILED, ScheduleAction::FAILED, ScheduleAction::STARTED],
            $this->outcomes($run),
            'One broken declaration must not stop the others from ticking.',
        );
        $this->assertStringContainsString('no such class exists', (string) $run->actions[0]->detail);
        $this->assertStringContainsString('schedule() returns null', (string) $run->actions[1]->detail);
        $this->assertTrue($run->needsAttention());
    }

    public function test_the_shared_budget_bounds_a_run_and_the_next_run_continues(): void
    {
        NightlyWorkflow::$policy = MissedTickPolicy::CatchUp;
        config()->set('laraflow.schedules.catch_up_window', 7 * 86400);
        config()->set('laraflow.schedules.limit', 2);

        $scheduler = $this->scheduler();
        $scheduler->run();

        $this->clock->set('2026-11-07 02:00:30');

        $this->assertCount(2, $this->scheduler()->run()->actions, 'Two of four due ticks: the budget is shared and bounded.');
        $this->assertSame(2, WorkflowExecutionModel::query()->count());

        $this->scheduler()->run();
        $this->assertSame(4, WorkflowExecutionModel::query()->count());
    }

    public function test_queued_delivery_hands_the_execution_to_a_worker(): void
    {
        config()->set('queue.default', 'database');

        $scheduler = $this->scheduler();
        $scheduler->run();
        $this->clock->set('2026-11-04 02:00:30');

        $run = $scheduler->run();

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($run));
        $this->assertSame('queued', $run->actions[0]->delivery);
        $this->assertTrue($this->executions()->find($this->startedId($run))->isPending());
        $this->assertSame(1, $this->app->make('db')->table('jobs')->count());
    }

    public function test_sync_runs_the_execution_in_this_process(): void
    {
        config()->set('queue.default', 'database');

        $scheduler = $this->scheduler();
        $scheduler->run();
        $this->clock->set('2026-11-04 02:00:30');

        $run = $scheduler->run(sync: true);

        $this->assertSame(ExecutionStatus::Completed->value, $run->actions[0]->delivery);
        $this->assertTrue($this->executions()->find($this->startedId($run))->isCompleted());
        $this->assertSame(0, $this->app->make('db')->table('jobs')->count(), 'Nothing waited for a worker.');
    }

    public function test_dry_run_names_the_ticks_it_would_claim_and_claims_none(): void
    {
        $dry = $this->scheduler()->run(dryRun: true);
        $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($dry));
        $this->assertStringContainsString('would register', (string) $dry->actions[0]->detail);
        $this->assertNull($this->schedules()->find(NightlyWorkflow::class), 'A dry run writes nothing, the row included.');

        $scheduler = $this->scheduler();
        $scheduler->run();
        $this->clock->set('2026-11-04 02:00:30');

        $run = $scheduler->run(dryRun: true);

        $this->assertSame([ScheduleAction::WOULD_START], $this->outcomes($run));
        $this->assertSame('2026-11-04 02:00:00', $run->actions[0]->tickAt?->format('Y-m-d H:i:s'));
        $this->assertSame(0, ScheduleTickModel::query()->count());
        $this->assertSame(0, WorkflowExecutionModel::query()->count());
    }

    public function test_the_kill_switch_stops_every_schedule_without_unlisting_any(): void
    {
        config()->set('laraflow.schedules.enabled', false);

        $this->clock->set('2026-11-04 02:00:30');
        $run = $this->scheduler()->run();

        $this->assertFalse($run->enabled);
        $this->assertSame([], $run->actions);
        $this->assertSame(0, ScheduleTickModel::query()->count());
    }

    public function test_an_orphaned_row_is_reported_and_never_deleted(): void
    {
        $this->scheduler()->run();

        config()->set('laraflow.schedules.workflows', []);

        $run = $this->scheduler()->run();

        $this->assertSame([NightlyWorkflow::class], $run->orphans);
        $this->assertTrue($run->needsAttention(), 'A schedule that stopped running is what the health check surfaces.');
        $this->assertNotNull($this->schedules()->find(NightlyWorkflow::class), 'Reported, not deleted.');
    }

    public function test_sync_writes_the_declaration_to_the_row_and_leaves_the_history_alone(): void
    {
        $scheduler = $this->scheduler();
        $scheduler->run();

        $before = $this->schedules()->find(NightlyWorkflow::class);
        $this->assertNotNull($before);

        NightlyWorkflow::$cron = '30 3 * * *';
        NightlyWorkflow::$policy = MissedTickPolicy::CatchUp;
        NightlyWorkflow::$tenant = 'acme';

        $this->clock->advance(60);
        $scheduler->run();

        $after = $this->schedules()->find(NightlyWorkflow::class);
        $this->assertNotNull($after);
        $this->assertSame('30 3 * * *', $after->cron);
        $this->assertSame(MissedTickPolicy::CatchUp, $after->missedTickPolicy);
        $this->assertSame('acme', $after->tenantId);
        $this->assertEquals($before->lastTickAt, $after->lastTickAt, 'The declaration owns the cadence; the row owns the history.');
    }

    public function test_the_timezone_decides_what_the_expression_means_and_utc_is_what_is_stored(): void
    {
        NightlyWorkflow::$timezone = 'America/New_York';

        $scheduler = $this->scheduler();
        $scheduler->run();

        // 02:00 in New York (EST in November) is 07:00 UTC.
        $this->clock->set('2026-11-04 10:00:00');
        $run = $scheduler->run();

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($run));
        $this->assertSame('2026-11-04 07:00:00', ScheduleTickModel::query()->firstOrFail()->tick_at->format('Y-m-d H:i:s'));
    }

    public function test_the_ticked_event_fires_after_the_commit_with_identifiers_and_instants(): void
    {
        NightlyWorkflow::$tenant = 'acme';

        Event::fake([WorkflowScheduleTicked::class]);

        $scheduler = $this->scheduler();
        $scheduler->run();
        $this->clock->set('2026-11-04 02:00:30');

        $run = $scheduler->run();
        $started = $this->startedId($run);

        Event::assertDispatched(WorkflowScheduleTicked::class, function (WorkflowScheduleTicked $event) use ($started): bool {
            return $event->executionId === $started
                && $event->workflow === NightlyWorkflow::class
                && $event->correlationId === 'schedule:NightlyWorkflow@2026-11-04T02:00Z'
                && $event->tenantId === 'acme'
                && $event->tickAt->format('Y-m-d H:i') === '2026-11-04 02:00'
                && $event->missed === 0
                && $event->caughtUp === false;
        });
    }

    /**
     * The Milestone 10 audit's worst finding, held closed: when columns
     * held the application's wall-clock, a scheduler that normalised
     * instants to UTC fired hourly schedules once per hour *plus the
     * offset* and re-dispatched duplicates on every run in any non-UTC
     * application. Columns now hold UTC whatever zone an instant comes
     * in (UtcTimestampsTest); this holds the end-to-end behaviour. The
     * suite otherwise runs in UTC, which is exactly how it hid.
     */
    public function test_schedules_keep_time_in_an_application_that_is_not_utc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            NightlyWorkflow::$cron = '0 * * * *';
            $this->clock->set(new DateTimeImmutable('2026-11-03 09:30:00', new DateTimeZone('America/New_York')));

            $scheduler = $this->scheduler();
            $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($scheduler->run()), 'Registration never backfills, in any zone.');

            $this->clock->set(new DateTimeImmutable('2026-11-03 10:00:30', new DateTimeZone('America/New_York')));

            $run = $scheduler->run();
            $this->assertSame([ScheduleAction::STARTED], $this->outcomes($run));
            $this->assertSame(
                '2026-11-03 15:00',
                $run->actions[0]->tickAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
                '10:00 New York is 15:00 UTC: the instant, not the wall-clock, is what the tick names.',
            );

            $again = $scheduler->run();
            $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($again), 'The claimed tick reads back as the instant it was.');
            $this->assertSame([], $again->redispatched, 'A freshly created execution is not a lost dispatch.');
            $this->assertSame(1, WorkflowExecutionModel::query()->count());

            $execution = $this->executions()->find($this->startedId($run));
            $this->assertSame('schedule:NightlyWorkflow@2026-11-03T15:00Z', $execution->correlationId, 'The correlation id renders the instant as UTC wherever the app lives.');
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * docs/schedules.md, "Registration": a package registers through the
     * registry from its provider, and both paths end in the same place.
     */
    public function test_a_programmatically_registered_schedule_ticks_like_a_listed_one(): void
    {
        config()->set('laraflow.schedules.workflows', []);
        $this->app->make(ScheduleRegistry::class)->register(NightlyWorkflow::class);

        $scheduler = $this->scheduler();
        $scheduler->run();
        $this->clock->set('2026-11-04 02:00:30');

        $this->assertSame([ScheduleAction::STARTED], $this->outcomes($scheduler->run()));
    }

    /**
     * The race itself, at the claim: two schedulers read the same
     * high-water mark (the real interleaving — B's decision predates
     * A's commit), both create their execution inside their claiming
     * transaction, and the unique tick index lets exactly one commit.
     * The loser's transaction rolls back whole, execution included:
     * nothing ran twice, and nothing half-exists.
     */
    public function test_a_losing_claim_rolls_back_the_execution_it_created(): void
    {
        $this->scheduler()->run();
        $this->clock->set('2026-11-04 02:00:30');

        $runner = $this->app->make(WorkflowRunner::class);
        $tickAt = new DateTimeImmutable('2026-11-04 02:00:00', new DateTimeZone('UTC'));

        // Two schedulers, one row, read before either claims.
        $readByA = $this->schedules()->find(NightlyWorkflow::class);
        $readByB = $this->schedules()->find(NightlyWorkflow::class);
        $this->assertNotNull($readByA);
        $this->assertNotNull($readByB);

        $won = $this->schedules()->claimTick(
            $readByA,
            $tickAt,
            TickOutcome::Started,
            fn (): WorkflowExecution => $runner->start(new NightlyWorkflow),
            $this->clock->now(),
        );

        $this->assertNotNull($won->workflowExecutionId);

        try {
            $this->schedules()->claimTick(
                $readByB,
                $tickAt,
                TickOutcome::Started,
                fn (): WorkflowExecution => $runner->start(new NightlyWorkflow),
                $this->clock->now(),
            );

            $this->fail('The second claim of one tick must lose to the unique index.');
        } catch (TickAlreadyClaimedException) {
            // The designed outcome for every claimant but one.
        }

        $this->assertSame(1, ScheduleTickModel::query()->count(), 'One tick row, whoever read first.');
        $this->assertSame(
            1,
            WorkflowExecutionModel::query()->count(),
            "The loser's execution rolled back with its tick: the plan rows, the execution, all of it.",
        );
        $this->assertSame(
            '2026-11-04 02:00:00',
            $this->schedules()->find(NightlyWorkflow::class)?->lastTickAt?->format('Y-m-d H:i:s'),
            'The high-water mark advanced exactly once, to the claimed instant.',
        );
    }

    /**
     * Resolved per call, after the test has set its configuration —
     * SchedulerOptions is a singleton elsewhere, and a cached one would
     * read yesterday's config.
     */
    protected function scheduler(): Scheduler
    {
        $this->app->forgetInstance(SchedulerOptions::class);
        $this->app->forgetInstance(Scheduler::class);

        return $this->app->make(Scheduler::class);
    }

    protected function schedules(): ScheduleRepository
    {
        return $this->app->make(ScheduleRepository::class);
    }

    protected function executions(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }

    /**
     * @return list<string>
     */
    protected function outcomes(SchedulerRun $run): array
    {
        return array_map(fn (ScheduleAction $action): string => $action->outcome, $run->actions);
    }

    protected function startedId(SchedulerRun $run): int
    {
        foreach ($run->actions as $action) {
            if ($action->executionId !== null) {
                return $action->executionId;
            }
        }

        $this->fail('No action carried an execution id.');
    }
}
