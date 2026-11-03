<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Laraflow\Exceptions\Workflow\FanOutFailedException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\FanOutWorkflow;
use Laraflow\Tests\Support\NotifyMembersStep;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;

/**
 * Cancellation and self-healing at width (docs/advanced-workflows.md,
 * "Cancellation and retry" and the heartbeat): the cascade reaches
 * every member, the group never hangs on a lost signal or a lost
 * dispatch, and nothing can leave it waiting forever.
 */
class FanOutRecoveryTest extends TestCase
{
    public function test_cancelling_the_parent_cascades_to_every_member_without_a_signal_storm(): void
    {
        Queue::fake();
        $logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $logger);

        NotifyMembersStep::$inputs = array_map(fn (int $n): array => ['n' => $n], range(1, 25));

        $parent = $this->runner()->run(new FanOutWorkflow);
        $this->assertTrue($parent->isWaiting());
        $this->assertSame(25, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count());

        $this->runner()->cancel($parent->id, 'the campaign was pulled');

        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status);
        $this->assertSame(
            25,
            WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->where('status', ExecutionStatus::Cancelled->value)->count(),
            'Every member was cancelled by the cascade.',
        );

        // A cancelled parent cannot receive a signal, so no member
        // tries: 25 refusals would be 25 log lines, and a thousand
        // members a thousand.
        $this->assertSame(
            [],
            array_filter($logger->records, fn (array $record): bool => str_contains((string) $record['message'], 'could not signal a parent')),
            'No signal storm.',
        );
    }

    public function test_a_member_created_while_the_parent_is_being_cancelled_is_caught_at_the_boundary(): void
    {
        Queue::fake();

        // Cancellation is requested while the step is mid-creation: the
        // parent is running, so the request is honoured at its next
        // step boundary, and the cascade runs there too — catching the
        // members this very step is making.
        $parent = $this->runner()->start(new FanOutWorkflow);
        NotifyMembersStep::$inputs = [['n' => 1], ['n' => 2]];

        $this->runner()->cancel($parent->id, 'pulled mid-flight');
        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status, 'Unclaimed work is cancelled outright.');

        // Nothing runs afterwards: cancelled is terminal.
        $this->assertSame(0, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count());
    }

    public function test_a_lost_join_signal_is_healed_by_the_heartbeat(): void
    {
        Queue::fake();
        Date::setTestNow('2026-11-02 10:00:00');

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();

        foreach ($members as $member) {
            $this->runner()->execute($member->id, new TwoStepWorkflow);
        }

        // The join signal is lost — a crash between the member's
        // release and the parent's write, say. The step row carries no
        // output and the parent is still parked.
        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();
        StepExecutionModel::query()->whereKey($stepRow->id)->update(['output' => null]);
        $this->assertTrue(WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status === ExecutionStatus::Waiting->value);

        // The heartbeat comes due and the parent consults the record
        // directly: the group is done, so the step completes anyway.
        Date::setTestNow('2026-11-02 10:05:00');
        $resumed = $this->runner()->execute($parent->id, new FanOutWorkflow);

        $this->assertTrue($resumed->isCompleted(), 'The record answers even when the signal did not arrive.');
        $this->assertSame(3, StepExecutionModel::query()->whereKey($stepRow->id)->sole()->output['completed'] ?? null);
    }

    public function test_a_member_whose_dispatch_was_lost_is_re_delivered_by_the_heartbeat(): void
    {
        Queue::fake();
        Date::setTestNow('2026-11-02 10:00:00');

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();

        // Two run; the third's job never reached a worker.
        $this->runner()->execute($this->row($members, 0, 'member')->id, new TwoStepWorkflow);
        $this->runner()->execute($this->row($members, 1, 'member')->id, new TwoStepWorkflow);
        $lost = $this->row($members, 2, 'member');
        $this->assertSame(ExecutionStatus::Pending->value, WorkflowExecutionModel::query()->whereKey($lost->id)->sole()->status);

        Date::setTestNow('2026-11-02 10:05:00');
        $stillWaiting = $this->runner()->execute($parent->id, new FanOutWorkflow);

        $this->assertTrue($stillWaiting->isWaiting(), 'Not done: one member never ran.');
        $this->assertSame(
            '2026-11-02 10:10:00',
            WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->next_retry_at?->format('Y-m-d H:i:s'),
            'A fresh heartbeat was scheduled.',
        );

        $deliveries = 0;
        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job) use ($lost, &$deliveries): bool {
            if ($job->executionId === $lost->id) {
                $deliveries++;
            }

            return true;
        });
        $this->assertSame(2, $deliveries, 'The lost dispatch was re-delivered; the members that ran were not.');
    }

    public function test_a_group_whose_members_were_pruned_fails_rather_than_hanging(): void
    {
        Queue::fake();
        Date::setTestNow('2026-11-02 10:00:00');

        $parent = $this->runner()->run(new FanOutWorkflow);

        // Retention took the members after the parent parked — the
        // documented trade-off of linking without foreign keys.
        WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->delete();

        Date::setTestNow('2026-11-02 10:05:00');

        try {
            $this->runner()->execute($parent->id, new FanOutWorkflow);
            $this->fail('A join with no members cannot be finished by anyone.');
        } catch (FanOutFailedException $exception) {
            $this->assertStringContainsString('no member execution is linked to it', $exception->getMessage());
        }

        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status);
    }

    public function test_a_join_signal_that_names_another_step_fails_the_step_by_name(): void
    {
        Queue::fake();

        $parent = $this->runner()->run(new FanOutWorkflow);
        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();

        // Only forJoin() writes this column, and it writes this row's
        // own id. A row altered outside the package says so.
        StepExecutionModel::query()->whereKey($stepRow->id)->update(['wait_signal' => 'laraflow.join.oops']);

        try {
            $this->runner()->execute($parent->id, new FanOutWorkflow, ignoreRetrySchedule: true);
            $this->fail('A malformed join signal must fail the step.');
        } catch (FanOutFailedException $exception) {
            $this->assertStringContainsString('[laraflow.join.oops]', $exception->getMessage());
            $this->assertStringContainsString('does not name a step', $exception->getMessage());
        }
    }

    public function test_a_waiting_fan_out_is_visible_to_recovery_as_a_due_wait(): void
    {
        Queue::fake();
        Date::setTestNow('2026-11-02 10:00:00');

        $parent = $this->runner()->run(new FanOutWorkflow);

        // The heartbeat is an ordinary due-wait, so laraflow:recover
        // delivers it with no new scan — the contract's "no new windows".
        Date::setTestNow('2026-11-02 10:05:01');

        $due = $this->app->make(\Laraflow\Workflow\ExecutionRepository::class)
            ->waitingDue(new DateTimeImmutable('2026-11-02 10:05:01'), 10);

        $this->assertSame([$parent->id], array_map(fn ($execution): int => $execution->id, $due));
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
