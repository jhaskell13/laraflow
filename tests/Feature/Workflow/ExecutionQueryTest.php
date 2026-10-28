<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;

/**
 * The stable listing API: every filter, keyset pagination that stays
 * correct while rows are being inserted, the page-size bound, and
 * whole-execution attempt history in one query.
 *
 * Built through the real repository write path — create, claim, mark,
 * release — rather than by seeding rows, so the filters are proven
 * against the state the runner actually produces.
 */
class ExecutionQueryTest extends TestCase
{
    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_an_empty_filter_lists_everything_newest_first(): void
    {
        $a = $this->create();
        $b = $this->create();
        $c = $this->create();

        $page = $this->repository()->list(ExecutionFilter::all());

        $this->assertSame([$c->id, $b->id, $a->id], $this->ids($page->executions));
        $this->assertFalse($page->hasMore());
        $this->assertSame(3, count($page));
    }

    public function test_oldest_first_reverses_the_order(): void
    {
        $a = $this->create();
        $b = $this->create();

        $page = $this->repository()->list(new ExecutionFilter(oldestFirst: true));

        $this->assertSame([$a->id, $b->id], $this->ids($page->executions));
    }

    public function test_it_filters_by_one_or_several_workflows(): void
    {
        $sync = $this->create(workflow: 'App\Sync');
        $bill = $this->create(workflow: 'App\Bill');
        $this->create(workflow: 'App\Other');

        $one = $this->repository()->list(new ExecutionFilter(workflow: 'App\Sync'));
        $this->assertSame([$sync->id], $this->ids($one->executions));

        $two = $this->repository()->list(new ExecutionFilter(workflow: ['App\Sync', 'App\Bill']));
        $this->assertSame([$bill->id, $sync->id], $this->ids($two->executions));
    }

    public function test_it_filters_by_one_or_several_statuses(): void
    {
        $pending = $this->create();
        $completed = $this->finish($this->create(), 'completed');
        $failed = $this->finish($this->create(), 'failed');

        $only = $this->repository()->list(new ExecutionFilter(status: ExecutionStatus::Failed));
        $this->assertSame([$failed->id], $this->ids($only->executions));

        $both = $this->repository()->list(new ExecutionFilter(
            status: [ExecutionStatus::Completed, ExecutionStatus::Failed],
        ));
        $this->assertSame([$failed->id, $completed->id], $this->ids($both->executions));
        $this->assertNotContains($pending->id, $this->ids($both->executions));
    }

    public function test_it_filters_by_correlation_and_tenant(): void
    {
        $match = $this->create(options: new ExecutionOptions('req-1', 'acme'));
        $this->create(options: new ExecutionOptions('req-2', 'acme'));
        $this->create(options: new ExecutionOptions('req-1', 'globex'));
        $this->create();

        $byCorrelation = $this->repository()->list(new ExecutionFilter(correlationId: 'req-1'));
        $this->assertCount(2, $byCorrelation);

        $byTenant = $this->repository()->list(new ExecutionFilter(tenantId: 'acme'));
        $this->assertCount(2, $byTenant);

        $byBoth = $this->repository()->list(new ExecutionFilter(correlationId: 'req-1', tenantId: 'acme'));
        $this->assertSame([$match->id], $this->ids($byBoth->executions));
    }

    public function test_creation_windows_are_half_open(): void
    {
        Date::setTestNow('2026-10-01 10:00:00');
        $before = $this->create();
        Date::setTestNow('2026-10-01 11:00:00');
        $atStart = $this->create();
        Date::setTestNow('2026-10-01 11:30:00');
        $inside = $this->create();
        Date::setTestNow('2026-10-01 12:00:00');
        $atEnd = $this->create();

        $page = $this->repository()->list(new ExecutionFilter(
            createdAfter: new DateTimeImmutable('2026-10-01 11:00:00'),
            createdBefore: new DateTimeImmutable('2026-10-01 12:00:00'),
            oldestFirst: true,
        ));

        // [11:00, 12:00): the start is in, the end is out, so consecutive
        // hourly windows neither overlap nor leave a gap.
        $this->assertSame([$atStart->id, $inside->id], $this->ids($page->executions));
        $this->assertNotContains($before->id, $this->ids($page->executions));
        $this->assertNotContains($atEnd->id, $this->ids($page->executions));
    }

    public function test_a_finish_window_only_matches_finished_executions(): void
    {
        $running = $this->create();
        $this->repository()->claim($running->id, 'worker', 300);

        $early = $this->finish($this->create(), 'completed', '2026-10-01 09:00:00');
        $inWindow = $this->finish($this->create(), 'failed', '2026-10-01 10:30:00');
        $cancelled = $this->finish($this->create(), 'cancelled', '2026-10-01 10:45:00');

        $page = $this->repository()->list(new ExecutionFilter(
            finishedAfter: new DateTimeImmutable('2026-10-01 10:00:00'),
            finishedBefore: new DateTimeImmutable('2026-10-01 11:00:00'),
            oldestFirst: true,
        ));

        $this->assertSame([$inWindow->id, $cancelled->id], $this->ids($page->executions));
        $this->assertNotContains($early->id, $this->ids($page->executions));
        $this->assertNotContains($running->id, $this->ids($page->executions), 'A running execution has no finish.');
    }

    public function test_filters_combine_conjunctively(): void
    {
        $match = $this->finish($this->create(workflow: 'App\Sync', options: new ExecutionOptions(tenantId: 'acme')), 'failed');
        $this->finish($this->create(workflow: 'App\Sync', options: new ExecutionOptions(tenantId: 'acme')), 'completed');
        $this->finish($this->create(workflow: 'App\Other', options: new ExecutionOptions(tenantId: 'acme')), 'failed');
        $this->finish($this->create(workflow: 'App\Sync', options: new ExecutionOptions(tenantId: 'globex')), 'failed');

        $page = $this->repository()->list(new ExecutionFilter(
            workflow: 'App\Sync',
            status: ExecutionStatus::Failed,
            tenantId: 'acme',
        ));

        $this->assertSame([$match->id], $this->ids($page->executions));
    }

    // ------------------------------------------------------------------
    // Counting
    // ------------------------------------------------------------------

    public function test_count_matches_the_filter_and_ignores_pagination(): void
    {
        foreach (range(1, 7) as $i) {
            $this->finish($this->create(), $i % 2 === 0 ? 'failed' : 'completed');
        }

        $repository = $this->repository();

        $this->assertSame(7, $repository->count(ExecutionFilter::all()));
        $this->assertSame(3, $repository->count(new ExecutionFilter(status: ExecutionStatus::Failed)));
        $this->assertSame(4, $repository->count(new ExecutionFilter(status: ExecutionStatus::Completed)));
        $this->assertSame(0, $repository->count(new ExecutionFilter(status: ExecutionStatus::Running)));

        // The count is of the whole matching set, not of a page.
        $page = $repository->list(ExecutionFilter::all(), limit: 2);
        $this->assertCount(2, $page);
        $this->assertSame(7, $repository->count(ExecutionFilter::all()));
    }

    // ------------------------------------------------------------------
    // Pagination
    // ------------------------------------------------------------------

    public function test_pages_chain_through_the_cursor_without_overlap_or_gap(): void
    {
        $all = array_map(fn () => $this->create()->id, range(1, 7));
        $expected = array_reverse($all); // newest first

        $repository = $this->repository();
        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $repository->list(ExecutionFilter::all(), limit: 3, cursor: $cursor);
            $seen = [...$seen, ...$this->ids($page->executions)];
            $cursor = $page->nextCursor;
            $pages++;
        } while ($page->hasMore());

        $this->assertSame($expected, $seen);
        $this->assertSame(3, $pages, 'Seven rows at three per page is three pages.');
    }

    public function test_the_last_page_knows_it_is_last_without_another_round_trip(): void
    {
        $this->create();
        $this->create();
        $this->create();

        $exact = $this->repository()->list(ExecutionFilter::all(), limit: 3);

        $this->assertCount(3, $exact);
        $this->assertFalse($exact->hasMore(), 'Exactly a page of rows is the last page.');
        $this->assertNull($exact->nextCursor);
    }

    public function test_a_cursor_is_stable_while_newer_rows_are_inserted(): void
    {
        $older = array_map(fn () => $this->create()->id, range(1, 4));

        $repository = $this->repository();
        $first = $repository->list(ExecutionFilter::all(), limit: 2);

        // Rows land between page fetches, as they do in production.
        $this->create();
        $this->create();

        $second = $repository->list(ExecutionFilter::all(), limit: 2, cursor: $first->nextCursor);

        // Offset pagination would have re-served the two rows the inserts
        // pushed down; keyset continues strictly past the cursor.
        $this->assertSame(array_slice(array_reverse($older), 0, 2), $this->ids($first->executions));
        $this->assertSame(array_slice(array_reverse($older), 2, 2), $this->ids($second->executions));
        $this->assertEmpty(array_intersect($this->ids($first->executions), $this->ids($second->executions)));
    }

    public function test_a_cursor_honours_the_oldest_first_direction(): void
    {
        $ids = array_map(fn () => $this->create()->id, range(1, 5));

        $repository = $this->repository();
        $filter = new ExecutionFilter(oldestFirst: true);

        $first = $repository->list($filter, limit: 2);
        $second = $repository->list($filter, limit: 2, cursor: $first->nextCursor);

        $this->assertSame(array_slice($ids, 0, 2), $this->ids($first->executions));
        $this->assertSame(array_slice($ids, 2, 2), $this->ids($second->executions));
    }

    public function test_a_cursor_applies_within_the_filter(): void
    {
        $sync = [];
        foreach (range(1, 5) as $i) {
            $sync[] = $this->create(workflow: 'App\Sync')->id;
            $this->create(workflow: 'App\Other');
        }

        $repository = $this->repository();
        $filter = new ExecutionFilter(workflow: 'App\Sync', oldestFirst: true);

        $first = $repository->list($filter, limit: 2);
        $second = $repository->list($filter, limit: 2, cursor: $first->nextCursor);
        $third = $repository->list($filter, limit: 2, cursor: $second->nextCursor);

        $this->assertSame(array_slice($sync, 0, 2), $this->ids($first->executions));
        $this->assertSame(array_slice($sync, 2, 2), $this->ids($second->executions));
        $this->assertSame(array_slice($sync, 4, 1), $this->ids($third->executions));
        $this->assertFalse($third->hasMore());
    }

    public function test_a_malformed_cursor_is_rejected_rather_than_guessed_at(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed execution cursor');

        $this->repository()->list(ExecutionFilter::all(), cursor: 'page-2');
    }

    public function test_an_empty_result_is_an_empty_last_page(): void
    {
        $page = $this->repository()->list(new ExecutionFilter(tenantId: 'nobody'));

        $this->assertTrue($page->isEmpty());
        $this->assertCount(0, $page);
        $this->assertFalse($page->hasMore());
        $this->assertNull($page->nextCursor);
        $this->assertSame([], iterator_to_array($page));
    }

    // ------------------------------------------------------------------
    // The page-size bound
    // ------------------------------------------------------------------

    public function test_the_page_size_is_bounded_by_the_contract(): void
    {
        $this->create();

        $page = $this->repository()->list(ExecutionFilter::all(), limit: PHP_INT_MAX);

        $this->assertSame(ExecutionRepository::MAX_PAGE_SIZE, $page->limit);
    }

    public function test_a_non_positive_limit_becomes_one(): void
    {
        $this->create();
        $this->create();

        $repository = $this->repository();

        foreach ([0, -5] as $limit) {
            $page = $repository->list(ExecutionFilter::all(), limit: $limit);

            $this->assertSame(1, $page->limit);
            $this->assertCount(1, $page);
            $this->assertTrue($page->hasMore());
        }
    }

    public function test_the_page_reports_the_limit_actually_applied(): void
    {
        $page = $this->repository()->list(ExecutionFilter::all(), limit: 25);

        $this->assertSame(25, $page->limit);
    }

    // ------------------------------------------------------------------
    // Step progress
    // ------------------------------------------------------------------

    public function test_step_progress_counts_steps_and_completions_per_execution(): void
    {
        $repository = $this->repository();

        $a = $repository->create('App\Sync', steps: ['One', 'Two', 'Three']);
        $b = $repository->create('App\Sync', steps: ['One']);

        $claimed = $repository->claim($a->id, 'worker', 300);
        foreach (array_slice($repository->steps($claimed), 0, 2) as $step) {
            $step->markRunning(new DateTimeImmutable);
            $step->markCompleted(['ok' => true], new DateTimeImmutable);
            $repository->saveStep($claimed, $step);
        }

        $progress = $repository->stepProgress([$a->id, $b->id]);

        $this->assertSame(['total' => 3, 'completed' => 2, 'skipped' => 0], $progress[(string) $a->id]);
        $this->assertSame(['total' => 1, 'completed' => 0, 'skipped' => 0], $progress[(string) $b->id]);
    }

    public function test_step_progress_is_scoped_to_the_ids_asked_for(): void
    {
        $repository = $this->repository();
        $mine = $repository->create('App\Sync', steps: ['One']);
        $repository->create('App\Sync', steps: ['One', 'Two']);

        $progress = $repository->stepProgress([$mine->id]);

        $this->assertCount(1, $progress);
        $this->assertSame(['total' => 1, 'completed' => 0, 'skipped' => 0], $progress[(string) $mine->id]);
    }

    public function test_step_progress_omits_executions_with_no_steps(): void
    {
        $repository = $this->repository();
        $planless = $repository->create('App\Sync');

        // Absent rather than zero, so a caller can tell "no plan" from
        // "nothing done yet".
        $this->assertSame([], $repository->stepProgress([$planless->id]));
    }

    public function test_step_progress_of_nothing_is_nothing(): void
    {
        $this->assertSame([], $this->repository()->stepProgress([]));
    }

    public function test_step_progress_reads_one_query_for_many_executions(): void
    {
        $repository = $this->repository();
        $ids = array_map(fn () => $repository->create('App\Sync', steps: ['One'])->id, range(1, 5));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $repository->stepProgress($ids);

        // The reason this method exists: a listing must not cost one
        // query per row.
        $this->assertCount(1, DB::getQueryLog());

        DB::disableQueryLog();
    }

    // ------------------------------------------------------------------
    // Whole-execution attempt history
    // ------------------------------------------------------------------

    public function test_attempt_history_covers_every_step_in_position_then_attempt_order(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('App\Sync', steps: ['First', 'Second', 'Third']);
        $execution = $repository->claim($execution->id, 'worker', 300);
        [$first, $second, $third] = $repository->steps($execution);

        // Written deliberately out of position order to prove the
        // ordering rests on position, not on insertion or id.
        $this->attempt($repository, $execution, $third, 1, StepAttemptStatus::Completed);
        $this->attempt($repository, $execution, $first, 1, StepAttemptStatus::Failed);
        $this->attempt($repository, $execution, $second, 2, StepAttemptStatus::Completed);
        $this->attempt($repository, $execution, $first, 2, StepAttemptStatus::Completed);
        $this->attempt($repository, $execution, $second, 1, StepAttemptStatus::TimedOut);

        $history = $repository->attemptHistory($execution);

        $this->assertSame(
            [
                [$first->id, 1], [$first->id, 2],
                [$second->id, 1], [$second->id, 2],
                [$third->id, 1],
            ],
            array_map(fn (StepAttempt $a): array => [$a->stepExecutionId, $a->attempt], $history),
        );
    }

    public function test_attempt_history_agrees_with_the_per_step_view(): void
    {
        $repository = $this->repository();
        $execution = $repository->create('App\Sync', steps: ['First', 'Second']);
        $execution = $repository->claim($execution->id, 'worker', 300);
        [$first, $second] = $repository->steps($execution);

        $this->attempt($repository, $execution, $first, 1, StepAttemptStatus::Completed);
        $this->attempt($repository, $execution, $second, 1, StepAttemptStatus::Failed);
        $this->attempt($repository, $execution, $second, 2, StepAttemptStatus::Completed);

        $perStep = [...$repository->attempts($first), ...$repository->attempts($second)];

        $this->assertEquals($perStep, $repository->attemptHistory($execution));
    }

    public function test_attempt_history_is_scoped_to_one_execution(): void
    {
        $repository = $this->repository();

        $mine = $repository->claim($repository->create('App\Sync', steps: ['First'])->id, 'w1', 300);
        $theirs = $repository->claim($repository->create('App\Sync', steps: ['First'])->id, 'w2', 300);

        $this->attempt($repository, $mine, $repository->steps($mine)[0], 1, StepAttemptStatus::Completed);
        $this->attempt($repository, $theirs, $repository->steps($theirs)[0], 1, StepAttemptStatus::Failed);
        $this->attempt($repository, $theirs, $repository->steps($theirs)[0], 2, StepAttemptStatus::Completed);

        $this->assertCount(1, $repository->attemptHistory($mine));
        $this->assertCount(2, $repository->attemptHistory($theirs));
    }

    public function test_attempt_history_is_empty_before_anything_finished(): void
    {
        $execution = $this->create();

        $this->assertSame([], $this->repository()->attemptHistory($execution));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function create(
        string $workflow = 'App\Sync',
        ?ExecutionOptions $options = null,
    ): WorkflowExecution {
        return $this->repository()->create($workflow, steps: ['First'], options: $options);
    }

    /**
     * Drive an execution to a terminal state through the real write path.
     */
    protected function finish(
        WorkflowExecution $execution,
        string $outcome,
        string $at = '2026-10-01 12:00:00',
    ): WorkflowExecution {
        $repository = $this->repository();
        $execution = $repository->claim($execution->id, 'worker', 300);
        $when = new DateTimeImmutable($at);

        match ($outcome) {
            'completed' => $execution->markCompleted($when),
            'failed' => $execution->markFailed('boom', 'RuntimeException', $when),
            'cancelled' => $execution->markCancelled($when, 'test'),
            default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
        };

        $repository->release($execution);

        return $repository->find($execution->id);
    }

    protected function attempt(
        ExecutionRepository $repository,
        WorkflowExecution $execution,
        StepExecution $step,
        int $attempt,
        StepAttemptStatus $status,
    ): void {
        $repository->saveStep($execution, $step, new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $step->id,
            attempt: $attempt,
            status: $status,
            startedAt: new DateTimeImmutable('2026-10-01 12:00:00'),
            endedAt: new DateTimeImmutable('2026-10-01 12:00:01'),
        ));
    }

    /**
     * @param  WorkflowExecution[]  $executions
     * @return list<int|string>
     */
    protected function ids(array $executions): array
    {
        return array_values(array_map(fn (WorkflowExecution $execution) => $execution->id, $executions));
    }

    protected function repository(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
