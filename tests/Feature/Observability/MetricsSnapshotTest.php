<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Observability;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laraflow\Demo\DemoWorkflow;
use Laraflow\Observability\MetricsSnapshot;
use Laraflow\Observability\Snapshots;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\NightlyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\AttentionScan;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;

/**
 * The snapshot behind every gauge (docs/metrics.md): exact where the
 * attention scan is bounded, the same answer where both report, and a
 * fixed number of queries however much there is to count.
 */
class MetricsSnapshotTest extends TestCase
{
    use RunsArtisanCommands;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-01 09:00:00');
    }

    public function test_an_empty_installation_is_all_zero_and_has_no_oldest_age(): void
    {
        $snapshot = $this->take();

        $this->assertSame([], $snapshot->executions);
        $this->assertNull($snapshot->oldestUnfinishedAgeSeconds);
        $this->assertSame(0, $snapshot->retriesDue);
        $this->assertSame(0, $snapshot->waitsDue);
        $this->assertSame(0, $snapshot->overdueSteps);
        $this->assertSame(0, $snapshot->deferred);
        $this->assertSame(0, $snapshot->stalledSchedules);
        $this->assertEquals(new DateTimeImmutable('2026-10-01 09:00:00'), $snapshot->takenAt);
    }

    public function test_it_counts_what_an_operator_may_own_by_workflow_and_status_and_nothing_terminal(): void
    {
        $runner = $this->runner();

        $runner->run(new DemoWorkflow, $this->input());
        $runner->start(new DemoWorkflow, $this->input());
        $runner->start(new DemoWorkflow, $this->input());
        $runner->start(new TwoStepWorkflow);
        $cancelled = $runner->start(new TwoStepWorkflow);
        $runner->cancel($cancelled->id);
        $failed = $runner->start(new TwoStepWorkflow);
        WorkflowExecutionModel::query()->whereKey($failed->id)->update(['status' => ExecutionStatus::Failed->value]);

        $snapshot = $this->take();

        $this->assertSame([
            DemoWorkflow::class => ['pending' => 2],
            TwoStepWorkflow::class => ['failed' => 1, 'pending' => 1],
        ], array_map(fn (array $statuses): array => $this->sorted($statuses), $snapshot->executions));
        $this->assertSame(3, $snapshot->count(ExecutionStatus::Pending));
        $this->assertSame(1, $snapshot->count(ExecutionStatus::Pending, TwoStepWorkflow::class));
        $this->assertSame(0, $snapshot->count(ExecutionStatus::Completed), 'Completed only grows; the event counters carry it.');
        $this->assertSame(0, $snapshot->count(ExecutionStatus::Cancelled));
        $this->assertSame([DemoWorkflow::class, TwoStepWorkflow::class], $snapshot->workflows());
    }

    public function test_the_oldest_unfinished_age_is_measured_from_creation(): void
    {
        $this->runner()->start(new DemoWorkflow, $this->input());

        Date::setTestNow('2026-10-01 09:30:00');
        $this->runner()->start(new DemoWorkflow, $this->input());

        Date::setTestNow('2026-10-01 10:00:00');

        $this->assertSame(3600, $this->take()->oldestUnfinishedAgeSeconds);
    }

    public function test_every_attention_field_is_the_size_of_the_list_the_summary_prints(): void
    {
        $runner = $this->runner();

        foreach (range(1, 6) as $i) {
            $runner->start(new DemoWorkflow, $this->input());
        }

        $rows = WorkflowExecutionModel::query()->orderBy('id')->pluck('id')->all();

        // Two retries due, one not yet due, one due but held by a tenant
        // ceiling — deferred, never due.
        $this->set($rows[0], ['status' => 'retrying', 'next_retry_at' => '2026-10-01 08:59:00']);
        $this->set($rows[1], ['status' => 'retrying', 'next_retry_at' => '2026-10-01 08:00:00']);
        $this->set($rows[2], ['status' => 'retrying', 'next_retry_at' => '2026-10-01 09:10:00']);
        $this->set($rows[3], ['status' => 'retrying', 'next_retry_at' => '2026-10-01 08:00:00', 'deferred_until' => '2026-10-01 09:05:00']);

        // A wait past its deadline, and a running execution with two
        // steps past theirs — one overdue execution, not two.
        $this->set($rows[4], ['status' => 'waiting', 'next_retry_at' => '2026-10-01 08:30:00']);
        $this->set($rows[5], ['status' => 'running']);
        StepExecutionModel::query()->where('workflow_execution_id', $rows[5])->update([
            'status' => StepStatus::Running->value,
            'timeout_at' => '2026-10-01 08:58:00',
        ]);

        $snapshot = $this->take();
        $report = $this->app->make(AttentionScan::class)->scan(new ExecutionFilter, $this->now());

        $this->assertSame(2, $snapshot->retriesDue);
        $this->assertSame(1, $snapshot->waitsDue);
        $this->assertSame(1, $snapshot->overdueSteps);
        $this->assertSame(1, $snapshot->deferred);

        $this->assertSame(count($report->dueRetries), $snapshot->retriesDue);
        $this->assertSame(count($report->dueWaits), $snapshot->waitsDue);
        $this->assertSame(count($report->overdueSteps), $snapshot->overdueSteps);
        $this->assertSame(count($report->deferred), $snapshot->deferred);
    }

    public function test_a_stalled_schedule_is_counted_as_the_summary_lists_it(): void
    {
        config()->set('laraflow.schedules.workflows', [NightlyWorkflow::class]);

        $clock = new FixedClock('2026-11-03 14:30:00');
        $this->app->instance(ClockInterface::class, $clock);

        $this->callCommand('laraflow:schedule');
        $now = new DateTimeImmutable('2026-11-07 02:01:00');

        $snapshot = $this->app->make(Snapshots::class)->take($now);

        $this->assertSame(1, $snapshot->stalledSchedules);
        $this->assertCount(1, $this->app->make(AttentionScan::class)->stalledSchedules(new ExecutionFilter, $now));
        $this->assertSame(0, $this->app->make(Snapshots::class)->take($now, new ExecutionFilter(tenantId: 'someone-else'))->stalledSchedules);
    }

    /**
     * The reason the snapshot counts instead of listing: the attention
     * scan stops at MAX_PAGE_SIZE, and a gauge that saturated there
     * would report "500" through every incident larger than that.
     */
    public function test_counts_are_exact_past_the_page_size_the_summary_stops_at(): void
    {
        $this->runner()->start(new DemoWorkflow, $this->input());
        $this->set(1, ['status' => 'retrying', 'next_retry_at' => '2026-10-01 08:00:00']);
        $this->copies(1, ExecutionRepository::MAX_PAGE_SIZE + 20);

        $snapshot = $this->take();

        $this->assertSame(ExecutionRepository::MAX_PAGE_SIZE + 21, $snapshot->retriesDue);
        $this->assertSame(ExecutionRepository::MAX_PAGE_SIZE + 21, $snapshot->count(ExecutionStatus::Retrying));
        $this->assertCount(ExecutionRepository::MAX_PAGE_SIZE, $this->app->make(AttentionScan::class)->scan(new ExecutionFilter, $this->now())->dueRetries);
    }

    public function test_the_query_count_does_not_grow_with_executions_or_workflows(): void
    {
        $this->runner()->start(new DemoWorkflow, $this->input());
        $few = $this->queriesFor(fn () => $this->take());

        foreach (range(1, 5) as $i) {
            $this->runner()->start(new TwoStepWorkflow);
            $this->runner()->start(new DemoWorkflow, $this->input());
        }

        $this->set(2, ['status' => 'retrying', 'next_retry_at' => '2026-10-01 08:00:00']);
        $this->copies(2, 50);

        $this->assertSame($few, $this->queriesFor(fn () => $this->take()));
    }

    public function test_a_scope_narrows_every_field_as_the_summarys_options_do(): void
    {
        $runner = $this->runner();

        $acme = $runner->start(new DemoWorkflow, $this->input(), new ExecutionOptions(tenantId: 'acme', correlationId: 'order-1'));
        $globex = $runner->start(new DemoWorkflow, $this->input(), new ExecutionOptions(tenantId: 'globex', correlationId: 'order-2'));

        foreach ([$acme, $globex] as $execution) {
            $this->set($execution->id, ['status' => 'retrying', 'next_retry_at' => '2026-10-01 08:00:00']);
        }

        $byTenant = $this->take(new ExecutionFilter(tenantId: 'acme'));
        $byCorrelation = $this->take(new ExecutionFilter(correlationId: 'order-2'));

        $this->assertSame(1, $byTenant->retriesDue);
        $this->assertSame(1, $byTenant->count(ExecutionStatus::Retrying));
        $this->assertSame(1, $byCorrelation->retriesDue);
        $this->assertSame(2, $this->take()->retriesDue);
    }

    public function test_a_scope_naming_statuses_or_a_finished_window_is_refused(): void
    {
        foreach ([
            new ExecutionFilter(status: ExecutionStatus::Failed),
            new ExecutionFilter(finishedAfter: new DateTimeImmutable('-1 day')),
        ] as $scope) {
            try {
                $this->take($scope);
                $this->fail('A scope that could only zero fields must be refused.');
            } catch (InvalidArgumentException $refused) {
                $this->assertStringContainsString('metrics snapshot', $refused->getMessage());
            }
        }
    }

    public function test_it_resolves_from_the_container_and_reads_only_the_public_contract(): void
    {
        $this->assertInstanceOf(Snapshots::class, $this->app->make(Snapshots::class));

        foreach (['Snapshots', 'MetricsSnapshot'] as $class) {
            $source = (string) file_get_contents(dirname(__DIR__, 3)."/src/Observability/{$class}.php");

            $this->assertStringNotContainsString('Models\\', $source, "{$class} must not reach the Eloquent models.");
            $this->assertStringNotContainsString('DB::', $source);
        }
    }

    protected function take(?ExecutionFilter $scope = null): MetricsSnapshot
    {
        return $this->app->make(Snapshots::class)->take($this->now(), $scope);
    }

    protected function now(): DateTimeImmutable
    {
        return Date::now()->toDateTimeImmutable();
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    protected function set(int $id, array $columns): void
    {
        WorkflowExecutionModel::query()->whereKey($id)->update($columns + ['finished_at' => null]);
    }

    /**
     * Insert $count copies of one execution row, ids aside: the cheap
     * way to a table larger than any page.
     */
    protected function copies(int $id, int $count): void
    {
        $row = (array) DB::table('laraflow_workflow_executions')->where('id', $id)->first();
        unset($row['id']);

        // Sized by columns: SQL Server binds at most 2100 parameters a
        // statement, and a hundred rows of this table is more than that.
        $perInsert = max(1, intdiv(2000, max(1, count($row))));

        foreach (array_chunk(array_fill(0, $count, $row), $perInsert) as $chunk) {
            DB::table('laraflow_workflow_executions')->insert($chunk);
        }
    }

    protected function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  array<string, int>  $statuses
     * @return array<string, int>
     */
    protected function sorted(array $statuses): array
    {
        ksort($statuses);

        return $statuses;
    }

    /**
     * @return array<string, mixed>
     */
    protected function input(): array
    {
        return ['sku' => 'SKU-1', 'quantity' => 1, 'fail' => false];
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
