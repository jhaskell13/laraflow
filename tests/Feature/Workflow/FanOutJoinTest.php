<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Laraflow\Exceptions\Workflow\FanOutFailedException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\FanOutWorkflow;
use Laraflow\Tests\Support\NotifyMembersStep;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\JoinPolicy;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The join's partial-failure contract (docs/advanced-workflows.md,
 * "Partial failure"): the join waits for every member; a group that
 * does not all complete fails the step with its shape; a retry resumes
 * the members that did not complete rather than re-charging them or
 * creating siblings; and a cancelled member is final.
 */
class FanOutJoinTest extends TestCase
{
    public function test_wait_all_is_the_only_policy_and_the_default(): void
    {
        $this->assertSame([JoinPolicy::WaitAll], JoinPolicy::cases(), 'One case ships; a second is additive.');
        $this->assertSame(JoinPolicy::WaitAll, (new NotifyMembersStep($this->app->make(\Laraflow\Workflow\FanOutWorkflows::class)))->joinPolicy());
    }

    public function test_a_failed_member_does_not_fail_the_join_until_every_member_has_rested(): void
    {
        Queue::fake();

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();

        // The first member fails terminally; the other two have not run.
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->execute($this->row($members, 0, 'member')->id, new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->whereKey($this->row($members, 0, 'member')->id)->sole()->status);

        // The join is not signalled and the parent is not re-dispatched:
        // one failure is not the group's answer while two are still going.
        Queue::assertNotPushed(ExecuteWorkflowJob::class, fn (ExecuteWorkflowJob $job): bool => $job->executionId === $parent->id);

        // The heartbeat comes due: the parent consults the record and
        // parks again rather than failing on the one it can see.
        $stillWaiting = $this->runner()->execute($parent->id, new FanOutWorkflow, ignoreRetrySchedule: true);
        $this->assertTrue($stillWaiting->isWaiting(), 'WaitAll: the group is not done.');

        // The other two complete. The last one to rest signals the join.
        $this->runner()->execute($this->row($members, 1, 'member')->id, new TwoStepWorkflow);
        $this->runner()->execute($this->row($members, 2, 'member')->id, new TwoStepWorkflow);

        Queue::assertPushed(ExecuteWorkflowJob::class, fn (ExecuteWorkflowJob $job): bool => $job->executionId === $parent->id);

        // Now the group has rested and the step fails with its shape.
        try {
            $this->runner()->execute($parent->id, new FanOutWorkflow);
            $this->fail('A group with a failed member must fail the step.');
        } catch (FanOutFailedException $exception) {
            $this->assertStringContainsString('fanned out to 3 member execution(s), of which 2 completed', $exception->getMessage());
            $this->assertStringContainsString('1 failed', $exception->getMessage());
            $this->assertStringContainsString('2 completed', $exception->getMessage());
            $this->assertStringContainsString('['.$this->row($members, 0, 'member')->id.']', $exception->getMessage(), 'The member that did not complete is named.');
        }

        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status);

        // An ordinary step failure, recorded on the step row like any other.
        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();
        $this->assertSame('failed', $stepRow->status);
        $this->assertSame(FanOutFailedException::class, $stepRow->error_class);
    }

    public function test_a_retry_resumes_the_members_that_did_not_complete_rather_than_charging_them_twice(): void
    {
        Queue::fake();
        Date::setTestNow('2026-11-02 10:00:00');
        FanOutWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 1, jitter: 0.0);

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();
        $failing = $this->row($members, 0, 'member');

        StepTwo::$failTimes = 1;

        try {
            $this->runner()->execute($failing->id, new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        $this->runner()->execute($this->row($members, 1, 'member')->id, new TwoStepWorkflow);
        $this->runner()->execute($this->row($members, 2, 'member')->id, new TwoStepWorkflow);

        // The group rests with one failure: the step fails under its
        // policy, which schedules a retry rather than failing the run.
        $retrying = $this->runner()->execute($parent->id, new FanOutWorkflow);
        $this->assertTrue($retrying->isRetrying());

        // The retry re-delivers the failed member — its failure predates
        // this attempt, so it is the attempt's work, not its answer —
        // and parks on the join again. Nothing new is created. (A real
        // retry is at least its backoff later; the clock says so, since
        // "predates" is a strict comparison of instants.)
        Date::setTestNow('2026-11-02 10:01:00');
        $deliveriesBefore = $this->deliveriesTo($failing->id);
        $resumed = $this->runner()->execute($parent->id, new FanOutWorkflow, ignoreRetrySchedule: true);

        $this->assertTrue($resumed->isWaiting(), 'Not charged twice: the stale failure is being resumed.');
        $this->assertSame($deliveriesBefore + 1, $this->deliveriesTo($failing->id), 'The failed member was handed back to a worker.');
        $this->assertSame(3, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count(), 'The same members, not siblings.');

        // The resumed member completes from its own checkpoint — step
        // one is skipped, step two runs again — and the join is done.
        $finished = $this->runner()->execute($failing->id, new TwoStepWorkflow);
        $this->assertTrue($finished->isCompleted());

        $done = $this->runner()->execute($parent->id, new FanOutWorkflow);

        $this->assertTrue($done->isCompleted());
        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();
        $this->assertSame(3, $stepRow->output['completed'] ?? null);
        $this->assertSame(2, $stepRow->attempts, 'The first attempt failed; the second completed.');
    }

    public function test_a_cancelled_member_fails_the_join_on_every_retry_until_an_operator_intervenes(): void
    {
        Queue::fake();
        FanOutWorkflow::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 1, jitter: 0.0);

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();
        $cancelled = $this->row($members, 0, 'member');

        $this->runner()->cancel($cancelled->id, 'operator withdrew it');
        $deliveredBeforeCancel = $this->deliveriesTo($cancelled->id);
        $this->runner()->execute($this->row($members, 1, 'member')->id, new TwoStepWorkflow);
        $this->runner()->execute($this->row($members, 2, 'member')->id, new TwoStepWorkflow);

        // First attempt: the group has rested, one cancelled.
        $retrying = $this->runner()->execute($parent->id, new FanOutWorkflow);
        $this->assertTrue($retrying->isRetrying());

        // The retry cannot resume a cancelled member, so the group
        // reports the same outcome again — and the policy is exhausted.
        try {
            $this->runner()->execute($parent->id, new FanOutWorkflow, ignoreRetrySchedule: true);
            $this->fail('A cancelled member is final.');
        } catch (FanOutFailedException $exception) {
            $this->assertStringContainsString('1 cancelled', $exception->getMessage());
            $this->assertStringContainsString('['.$cancelled->id.']', $exception->getMessage());
        }

        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status);
        $this->assertSame($deliveredBeforeCancel, $this->deliveriesTo($cancelled->id), 'A cancelled member is never handed to a worker again.');
        $this->assertSame(3, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count());
    }

    public function test_on_the_sync_driver_a_fresh_failure_fails_the_step_inline_without_parking(): void
    {
        // No Queue::fake(): members run inside their delivery, and the
        // first one fails. The group rests before the step would park,
        // so the failure is the step's answer now, not a heartbeat from now.
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new FanOutWorkflow);
            $this->fail('A fresh failure in the group fails the step.');
        } catch (FanOutFailedException $exception) {
            $this->assertStringContainsString('of which 2 completed', $exception->getMessage());
        }

        $parent = WorkflowExecutionModel::query()->whereNull('parent_execution_id')->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $parent->status);

        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();
        $this->assertNull($stepRow->wait_signal, 'Never parked.');
        $this->assertSame(1, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->where('status', 'failed')->count());
        $this->assertSame(2, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->where('status', 'completed')->count());
    }

    public function test_the_failure_names_at_most_a_few_members_and_says_there_are_more(): void
    {
        Queue::fake();
        NotifyMembersStep::$inputs = array_map(fn (int $n): array => ['n' => $n], range(1, 8));

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();

        // Seven fail, one completes.
        foreach ($members as $i => $member) {
            StepTwo::$failTimes = $i < 7 ? 1 : 0;

            try {
                $this->runner()->execute($member->id, new TwoStepWorkflow);
            } catch (RuntimeException) {
            }
        }

        try {
            $this->runner()->execute($parent->id, new FanOutWorkflow);
            $this->fail('Seven failures fail the step.');
        } catch (FanOutFailedException $exception) {
            $this->assertStringContainsString('7 failed', $exception->getMessage());
            $this->assertStringContainsString(', and more.', $exception->getMessage(), 'Bounded: five named, the rest counted.');
            $this->assertSame(5, preg_match_all('/\b\d+\b(?=[,\]])/', substr($exception->getMessage(), (int) strpos($exception->getMessage(), 'did not complete:'))), 'Five ids named.');
        }
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }

    private function deliveriesTo(int $executionId): int
    {
        $count = 0;

        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job) use ($executionId, &$count): bool {
            if ($job->executionId === $executionId) {
                $count++;
            }

            return true;
        });

        return $count;
    }
}
