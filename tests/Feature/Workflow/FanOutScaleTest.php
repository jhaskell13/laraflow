<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laraflow\Exceptions\Workflow\FanOutFailedException;
use Laraflow\Tests\Support\FanOutWorkflow;
use Laraflow\Tests\Support\NotifyMembersStep;
use Laraflow\Tests\Support\OneStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;

/**
 * The Milestone 9 exit criterion: a fan-out of a thousand members
 * completes, fails partially, and is cancelled mid-flight — on every
 * engine, since this runs in the engine matrix like every other test.
 *
 * The point is not that it is fast. It is that the cost of *deciding*
 * about a group does not grow with the group: the join asks one
 * indexed aggregate however many members there are, so a heartbeat on
 * a thousand costs what a heartbeat on three costs.
 */
class FanOutScaleTest extends TestCase
{
    /** The exit criterion's number. */
    private const MEMBERS = 1000;

    public function test_a_thousand_members_are_created_once_and_complete(): void
    {
        Queue::fake();
        $this->declareMembers(self::MEMBERS);

        $parent = $this->runner()->run(new FanOutWorkflow);

        $this->assertTrue($parent->isWaiting());
        $this->assertSame(self::MEMBERS, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count());
        $this->assertSame(1, NotifyMembersStep::$membersCalls, 'Read once, however wide.');

        // Finish every member by hand — the record is what the join
        // reads, and running a thousand workflows here would measure
        // the suite rather than the join.
        $this->finish($parent->id, ExecutionStatus::Completed);

        $resumed = $this->runner()->execute($parent->id, new FanOutWorkflow, ignoreRetrySchedule: true);

        $this->assertTrue($resumed->isCompleted());
    }

    public function test_deciding_about_the_group_costs_the_same_however_wide_it_is(): void
    {
        Queue::fake();

        // Three members, all finished: count the queries one heartbeat
        // takes to decide the group is done.
        $this->declareMembers(3);
        $small = $this->runner()->run(new FanOutWorkflow);
        $this->finish($small->id, ExecutionStatus::Completed);
        $narrow = $this->queriesToResume($small->id);

        // The same, a thousand wide.
        NotifyMembersStep::$membersCalls = 0;
        $this->declareMembers(self::MEMBERS);
        $wide = $this->runner()->run(new FanOutWorkflow);
        $this->finish($wide->id, ExecutionStatus::Completed);
        $broad = $this->queriesToResume($wide->id);

        // Not "about the same": the same. The join reads counts, and
        // the member ids only once, for the summary it completes with.
        $this->assertSame(
            $narrow,
            $broad,
            "Resuming a join cost {$narrow} queries at 3 members and {$broad} at ".self::MEMBERS.'; the cost must not grow with the group.',
        );
    }

    public function test_a_thousand_members_with_one_failure_fail_the_step_with_the_groups_shape(): void
    {
        Queue::fake();
        $this->declareMembers(self::MEMBERS);

        $parent = $this->runner()->run(new FanOutWorkflow);
        $this->finish($parent->id, ExecutionStatus::Completed);

        // One of the thousand failed.
        $odd = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->firstOrFail();
        WorkflowExecutionModel::query()->whereKey($odd->id)->update([
            'status' => ExecutionStatus::Failed->value,
            'error' => 'the one that got away',
        ]);

        try {
            $this->runner()->execute($parent->id, new FanOutWorkflow, ignoreRetrySchedule: true);
            $this->fail('A group with a failed member fails the step.');
        } catch (FanOutFailedException $exception) {
            $this->assertStringContainsString('fanned out to '.self::MEMBERS.' member execution(s), of which '.(self::MEMBERS - 1).' completed', $exception->getMessage());
            $this->assertStringContainsString('['.$odd->id.']', $exception->getMessage());
            // Bounded: a thousand ids in an error column is not a
            // diagnosis, it is a denial of service on the reader.
            $this->assertLessThan(500, strlen($exception->getMessage()));
        }
    }

    public function test_a_thousand_members_are_cancelled_mid_flight(): void
    {
        Queue::fake();
        Date::setTestNow('2026-11-02 10:00:00');
        $this->declareMembers(self::MEMBERS);

        $parent = $this->runner()->run(new FanOutWorkflow);

        // Half have finished; the rest are still out there.
        $half = WorkflowExecutionModel::query()
            ->where('parent_execution_id', $parent->id)
            ->orderBy('id')
            ->limit(self::MEMBERS / 2)
            ->pluck('id');

        WorkflowExecutionModel::query()->whereIn('id', $half)->update([
            'status' => ExecutionStatus::Completed->value,
            'finished_at' => '2026-11-02 10:00:30',
        ]);

        $this->runner()->cancel($parent->id, 'pulled mid-flight');

        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status);

        // Every unfinished member was cancelled; the finished ones were
        // left alone, because cancelling what already happened is a lie.
        $this->assertSame(
            self::MEMBERS / 2,
            WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->where('status', ExecutionStatus::Cancelled->value)->count(),
        );
        $this->assertSame(
            self::MEMBERS / 2,
            WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->where('status', ExecutionStatus::Completed->value)->count(),
        );
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }

    /**
     * How many queries one claim of a parked join takes.
     */
    private function queriesToResume(int $executionId): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->runner()->execute($executionId, new FanOutWorkflow, ignoreRetrySchedule: true);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function declareMembers(int $count): void
    {
        // One-step members: this test is about the group, not about
        // what a member does.
        NotifyMembersStep::$workflow = OneStepWorkflow::class;
        NotifyMembersStep::$inputs = array_map(fn (int $n): array => ['n' => $n], range(1, $count));
    }

    private function finish(int $parentId, ExecutionStatus $status): void
    {
        WorkflowExecutionModel::query()
            ->where('parent_execution_id', $parentId)
            ->update(['status' => $status->value, 'finished_at' => Date::now()]);
    }
}
