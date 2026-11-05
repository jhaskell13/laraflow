<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Observability\Snapshots;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\NightlyWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\Concerns\StoresTimestampsInUtc;
use Laraflow\Workflow\Models\ScheduleTickModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Scheduling\ScheduleAction;
use Laraflow\Workflow\Scheduling\Scheduler;
use Laraflow\Workflow\Scheduling\SchedulerOptions;
use Laraflow\Workflow\Scheduling\SchedulerRun;
use Laraflow\Workflow\StepStatus;
use Psr\Clock\ClockInterface;

/**
 * docs/compatibility.md, "Stored timestamps", enforced: every Laraflow
 * column holds UTC whatever `app.timezone` is, so the repeated autumn
 * hour — 05:00Z and 06:00Z are both 01:00 in New York on 1 November
 * 2026 — names two instants, not one. The rest of the suite runs in
 * UTC, where the old wall-clock convention and this one coincide;
 * these tests run where they do not.
 */
class UtcTimestampsTest extends TestCase
{
    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-11-01 04:30:00');
        $this->app->instance(ClockInterface::class, $this->clock);
    }

    /**
     * The v0.6.0 audit's finding: in New York the second hourly tick of
     * the repeated hour rendered to the first's wall-clock, met the
     * unique (schedule_id, tick_at) key, and was reported "taken" — a
     * tick lost and misreported. Hourly in UTC, so both are real ticks.
     */
    public function test_both_hourly_ticks_of_the_repeated_hour_start_in_a_new_york_app(): void
    {
        $this->inZone('America/New_York', function (): void {
            config()->set('laraflow.schedules.workflows', [NightlyWorkflow::class]);
            NightlyWorkflow::$cron = '0 * * * *';
            NightlyWorkflow::$timezone = 'UTC';

            $scheduler = $this->scheduler();
            $this->assertSame([ScheduleAction::NOT_DUE], $this->outcomes($scheduler->run()));

            $this->clock->set('2026-11-01 05:00:30+00:00');
            $this->assertSame([ScheduleAction::STARTED], $this->outcomes($scheduler->run()), '05:00Z, 01:00 EDT.');

            $this->clock->set('2026-11-01 06:00:30+00:00');
            $this->assertSame([ScheduleAction::STARTED], $this->outcomes($scheduler->run()), '06:00Z, 01:00 EST: a different instant, not a taken tick.');

            $this->assertSame(2, WorkflowExecutionModel::query()->count());
            $this->assertSame(
                ['2026-11-01 05:00:00', '2026-11-01 06:00:00'],
                $this->raw('laraflow_schedule_ticks', 'tick_at'),
                'Stored as UTC, which has no repeated hour.',
            );
            $this->assertSame(
                ['2026-11-01T05:00:00Z', '2026-11-01T06:00:00Z'],
                ScheduleTickModel::query()->orderBy('id')->get()->map(
                    fn (ScheduleTickModel $tick): string => $tick->tick_at->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                )->all(),
                'And read back as the instants they were.',
            );
        });
    }

    /**
     * A retry due at 01:30 EST (06:30Z) is not due at 01:45 EDT (05:45Z),
     * though 01:30 sorts before 01:45 on a New York wall-clock.
     */
    public function test_a_retry_is_not_due_early_across_the_repeated_hour(): void
    {
        $this->inZone('America/New_York', function (): void {
            $repository = $this->app->make(ExecutionRepository::class);
            $execution = $repository->create('App\\Workflows\\Sync', steps: ['App\\Steps\\One']);

            WorkflowExecutionModel::query()->whereKey($execution->id)->update([
                'status' => ExecutionStatus::Retrying->value,
                'next_retry_at' => $this->newYork('2026-11-01 06:30:00'),
            ]);

            // In the application's zone, as Laraflow's own clock and
            // every handle carry it: that is how the instants reached
            // the comparison as two New York wall-clocks.
            $this->clock->set($this->newYork('2026-11-01 05:45:00'));

            $this->assertSame([], $repository->dueForRetry($this->clock->now()), 'The recovery scan agrees: not due.');

            try {
                $repository->claim($execution->id, 'early', 300);
                $this->fail('A retry due in 45 minutes was claimed now.');
            } catch (RetryNotDueException) {
                // Not due until 06:30Z.
            }

            $this->clock->set($this->newYork('2026-11-01 06:30:00'));

            $this->assertCount(1, $repository->dueForRetry($this->clock->now()));
            $this->assertSame(ExecutionStatus::Running, $repository->claim($execution->id, 'due', 300)->status);
        });
    }

    /**
     * The snapshot's counts compare instants inside a grouped count and
     * an EXISTS subquery — bindings the model's own casts never see. The
     * repeated hour is where a wall-clock comparison would be wrong.
     */
    public function test_the_metrics_snapshot_counts_by_instant_across_the_repeated_hour(): void
    {
        $this->inZone('America/New_York', function (): void {
            $repository = $this->app->make(ExecutionRepository::class);
            $retrying = $repository->create('App\\Workflows\\Sync', steps: ['App\\Steps\\One']);
            $running = $repository->create('App\\Workflows\\Sync', steps: ['App\\Steps\\One']);

            WorkflowExecutionModel::query()->whereKey($retrying->id)->update([
                'status' => ExecutionStatus::Retrying->value,
                'next_retry_at' => $this->newYork('2026-11-01 06:30:00'),
            ]);
            WorkflowExecutionModel::query()->whereKey($running->id)->update(['status' => ExecutionStatus::Running->value]);
            StepExecutionModel::query()->where('workflow_execution_id', $running->id)->update([
                'status' => StepStatus::Running->value,
                'timeout_at' => $this->newYork('2026-11-01 06:30:00'),
            ]);

            $snapshots = $this->app->make(Snapshots::class);

            $early = $snapshots->take($this->newYork('2026-11-01 05:45:00'));
            $this->assertSame(0, $early->retriesDue, 'Due at 06:30Z, not at 05:45Z.');
            $this->assertSame(0, $early->overdueSteps);

            $due = $snapshots->take($this->newYork('2026-11-01 06:30:00'));
            $this->assertSame(1, $due->retriesDue);
            $this->assertSame(1, $due->overdueSteps);
        });
    }

    public function test_a_stored_instant_reads_back_identical_in_any_application_zone(): void
    {
        $dispatched = new DateTimeImmutable('2026-11-01 05:30:00', new DateTimeZone('UTC'));
        $available = new DateTimeImmutable('2026-11-01 01:30:00', new DateTimeZone('America/Chicago'));

        foreach (['America/New_York', 'Asia/Kolkata'] as $zone) {
            $this->inZone($zone, function () use ($zone, $dispatched, $available): void {
                // Outside the repeated hour: Carbon's test clock renders
                // and re-parses a wall-clock, which inside it is the
                // very ambiguity under test.
                Date::setTestNow(new DateTimeImmutable('2026-11-01 08:15:00', new DateTimeZone('UTC')));

                try {
                    $repository = $this->app->make(ExecutionRepository::class);
                    $id = $repository->create('App\\Workflows\\Sync')->id;
                    $repository->recordDispatch($id, $dispatched, $available);

                    $found = $repository->find($id);
                    $this->assertNotNull($found->dispatchedAt);
                    $this->assertNotNull($found->availableAt);
                    $this->assertNotNull($found->createdAt);

                    $this->assertSame($dispatched->getTimestamp(), $found->dispatchedAt->getTimestamp(), "[{$zone}] the dispatch instant.");
                    $this->assertSame($available->getTimestamp(), $found->availableAt->getTimestamp(), "[{$zone}] an instant given in a third zone.");
                    $this->assertSame($zone, $found->dispatchedAt->getTimezone()->getName(), "[{$zone}] rendered in the application's zone, as before.");

                    $row = (array) DB::table('laraflow_workflow_executions')->where('id', $id)->first();
                    $this->assertSame('2026-11-01 05:30:00', $this->seconds($row['dispatched_at']), "[{$zone}] stored as UTC.");
                    $this->assertSame('2026-11-01 06:30:00', $this->seconds($row['available_at']), "[{$zone}] stored as UTC.");
                    $this->assertSame('2026-11-01 08:15:00', $this->seconds($row['created_at']), "[{$zone}] Eloquent's own timestamps too.");
                    $this->assertSame('2026-11-01 08:15:00', $this->seconds($row['updated_at']), "[{$zone}] Eloquent's own timestamps too.");
                    $this->assertSame('2026-11-01T08:15:00+00:00', $found->createdAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
                } finally {
                    Date::setTestNow();
                }
            });
        }
    }

    public function test_every_laraflow_model_stores_utc(): void
    {
        foreach (glob(__DIR__.'/../../../src/Workflow/Models/*Model.php') ?: [] as $file) {
            $class = 'Laraflow\\Workflow\\Models\\'.basename($file, '.php');

            $traits = class_uses($class);
            $this->assertIsArray($traits, "{$class} must exist.");
            $this->assertContains(StoresTimestampsInUtc::class, $traits, "{$class} must store its timestamps as UTC.");
        }
    }

    /**
     * Run $test as an application configured for $zone — what Laravel
     * does at boot from app.timezone, done here mid-test.
     */
    private function inZone(string $zone, Closure $test): void
    {
        $previous = date_default_timezone_get();
        $configured = config('app.timezone');

        config()->set('app.timezone', $zone);
        date_default_timezone_set($zone);

        try {
            $test();
        } finally {
            date_default_timezone_set($previous);
            config()->set('app.timezone', $configured);
        }
    }

    /**
     * A UTC instant, carried in New York's zone.
     */
    private function newYork(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));
    }

    /**
     * @return list<string>
     */
    private function raw(string $table, string $column): array
    {
        return array_values(DB::table($table)->orderBy('id')->pluck($column)
            ->map(fn (mixed $value): string => $this->seconds($value))
            ->all());
    }

    /**
     * A stored value to the second: SQL Server returns fractions.
     */
    private function seconds(mixed $value): string
    {
        return substr((string) $value, 0, 19);
    }

    private function scheduler(): Scheduler
    {
        $this->app->forgetInstance(SchedulerOptions::class);
        $this->app->forgetInstance(Scheduler::class);

        return $this->app->make(Scheduler::class);
    }

    /**
     * @return list<string>
     */
    private function outcomes(SchedulerRun $run): array
    {
        return array_map(fn (ScheduleAction $action): string => $action->outcome, $run->actions);
    }
}
