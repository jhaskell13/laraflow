<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laraflow\Events\WorkflowFanOutStarted;
use Laraflow\Exceptions\Workflow\FanOutTooWideException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\FailingAfterDelivery;
use Laraflow\Tests\Support\FanOutWorkflow;
use Laraflow\Tests\Support\NotifyMembersStep;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ChildDelivery;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The fan-out contract (docs/advanced-workflows.md): one plan step whose
 * members are child executions, declared once and created in a
 * resumable way, parked on a join the last member to rest signals.
 */
class FanOutTest extends TestCase
{
    public function test_the_step_declares_its_members_before_creating_them_and_parks_on_the_join(): void
    {
        Queue::fake();
        Event::fake([WorkflowFanOutStarted::class]);
        Date::setTestNow('2026-11-02 10:00:00');

        $parent = $this->runner()->run(new FanOutWorkflow, [], new ExecutionOptions(correlationId: 'req-1', tenantId: 'acme'));

        $this->assertTrue($parent->isWaiting());

        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();

        // Declared, on the step row, before anything was created.
        $this->assertSame(1, NotifyMembersStep::$membersCalls, 'members() is read once.');
        $this->assertCount(3, $stepRow->input['members'] ?? []);
        $this->assertSame(TwoStepWorkflow::class, $stepRow->input['members'][0]['workflow'] ?? null);
        $this->assertEquals(['n' => 1], $stepRow->input['members'][0]['input'] ?? null);

        // Created: three children, each linked to this exact step and
        // carrying the parent's identifiers, as a sub-workflow's child is.
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();
        $this->assertCount(3, $members);

        foreach ($members as $i => $member) {
            $this->assertSame($stepRow->id, $member->parent_step_execution_id);
            $this->assertSame(TwoStepWorkflow::class, $member->workflow);
            $this->assertEquals(['n' => $i + 1], $member->input);
            $this->assertSame('req-1', $member->correlation_id);
            $this->assertSame('acme', $member->tenant_id);
            $this->assertSame(ExecutionStatus::Pending->value, $member->status);
        }

        // Parked on the join named by the step row, heartbeating.
        $this->assertSame("laraflow.join.{$stepRow->id}", $stepRow->wait_signal);
        $this->assertSame(
            '2026-11-02 10:05:00',
            WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->next_retry_at?->format('Y-m-d H:i:s'),
        );

        // Every member delivered, and the group announced once.
        Queue::assertPushed(ExecuteWorkflowJob::class, 3);
        Event::assertDispatched(WorkflowFanOutStarted::class, function (WorkflowFanOutStarted $event) use ($parent, $members): bool {
            return $event->executionId === $parent->id
                && $event->members === 3
                && $event->memberIds === $members->pluck('id')->all();
        });
    }

    public function test_the_last_member_to_rest_signals_the_join_and_the_parent_completes_with_a_summary(): void
    {
        Queue::fake();

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();
        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();

        // Two of three finish: no join signal yet, and the parent is
        // not re-dispatched for them.
        $this->runner()->execute($this->row($members, 0, 'member')->id, new TwoStepWorkflow);
        $this->runner()->execute($this->row($members, 1, 'member')->id, new TwoStepWorkflow);

        // Re-read each time rather than through one expression: the row
        // changes under the test, and the analyser would otherwise carry
        // an earlier assertion's narrowing onto a later read.
        $beforeLast = StepExecutionModel::query()->whereKey($stepRow->id)->sole();
        $this->assertNull($beforeLast->output, 'Not signalled: the group is not done.');
        Queue::assertNotPushed(ExecuteWorkflowJob::class, fn (ExecuteWorkflowJob $job): bool => $job->executionId === $parent->id);

        // The last one rests: it signals the join with the group's
        // counts and asks for the parent to be delivered.
        $this->runner()->execute($this->row($members, 2, 'member')->id, new TwoStepWorkflow);

        $afterLast = StepExecutionModel::query()->whereKey($stepRow->id)->sole();
        $signalled = $afterLast->output;
        $this->assertIsArray($signalled);
        $this->assertSame(3, $signalled['completed'] ?? null);
        $this->assertSame($stepRow->id, $signalled['parent_step_execution_id'] ?? null);
        Queue::assertPushed(ExecuteWorkflowJob::class, fn (ExecuteWorkflowJob $job): bool => $job->executionId === $parent->id);

        $resumed = $this->runner()->execute($parent->id, new FanOutWorkflow);

        $this->assertTrue($resumed->isCompleted());

        // The step's output is the group's shape — counts and ids,
        // never a member's context.
        $resumedRow = StepExecutionModel::query()->whereKey($stepRow->id)->sole();
        $output = $resumedRow->output;
        $this->assertIsArray($output);
        $this->assertSame(3, $output['total']);
        $this->assertSame(3, $output['completed']);
        $this->assertEquals($members->pluck('id')->all(), $output['members']);
        $this->assertArrayNotHasKey('one', $output, 'No member context crosses the boundary.');
    }

    public function test_on_the_sync_driver_the_group_runs_inline_and_the_step_completes_without_parking(): void
    {
        // No Queue::fake(): the sync connection runs each member inside
        // its delivery, so by the time the step would park, it is done.
        $parent = $this->runner()->run(new FanOutWorkflow);

        $this->assertTrue($parent->isCompleted());
        $this->assertSame(3, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->where('status', 'completed')->count());
        $this->assertSame(3, StepTwo::$runs, 'Every member ran to its last step.');

        $stepRow = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();
        $this->assertSame(3, $stepRow->output['completed'] ?? null);
        $this->assertNull($stepRow->wait_signal, 'Never parked.');
    }

    public function test_a_group_wider_than_the_bound_is_refused_before_anything_is_created(): void
    {
        Queue::fake();
        config()->set('laraflow.workflows.max_members', 2);

        try {
            $this->runner()->run(new FanOutWorkflow);
            $this->fail('A third member must be refused.');
        } catch (FanOutTooWideException $exception) {
            $this->assertStringContainsString('declared 3 fan-out members', $exception->getMessage());
            $this->assertStringContainsString('allows 2', $exception->getMessage());
        }

        $this->assertSame(0, WorkflowExecutionModel::query()->whereNotNull('parent_execution_id')->count(), 'Nothing was created.');
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->whereNull('parent_execution_id')->sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_member_that_is_not_a_fan_out_is_refused_by_name(): void
    {
        NotifyMembersStep::$yieldInstead = new TwoStepWorkflow;

        try {
            $this->runner()->run(new FanOutWorkflow);
            $this->fail('A bare workflow is not a member.');
        } catch (FanOutTooWideException $exception) {
            $this->assertStringContainsString(TwoStepWorkflow::class, $exception->getMessage());
            $this->assertStringContainsString('every member must be a', $exception->getMessage());
        }

        $this->assertSame(0, WorkflowExecutionModel::query()->whereNotNull('parent_execution_id')->count());
    }

    public function test_creation_resumes_from_the_members_that_exist_without_reading_members_again(): void
    {
        Queue::fake();

        // The worker dies handing over the second member: two members
        // exist (one delivered, one not), the third was never made.
        $delivery = new FailingAfterDelivery($this->app->make(ChildDelivery::class), dieOn: 2);
        $this->app->instance(ChildDelivery::class, $delivery);
        $this->app->forgetInstance(\Laraflow\Workflow\FanOutWorkflows::class);

        try {
            $this->runner()->run(new FanOutWorkflow);
            $this->fail('The dying delivery must surface as the step failing.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('died delivering member #2', $exception->getMessage());
        }

        $parent = WorkflowExecutionModel::query()->whereNull('parent_execution_id')->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $parent->status);
        $this->assertSame(2, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count(), 'Exactly the members made before the death.');
        $this->assertSame(1, NotifyMembersStep::$membersCalls);

        // The next claim reads the declaration from the row, counts what
        // exists, and makes only the remainder.
        $delivery->dieOn = 0;
        $resumed = $this->runner()->execute($parent->id, new FanOutWorkflow);

        $this->assertTrue($resumed->isWaiting(), 'Parked on the join with the group complete.');
        $this->assertSame(3, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count(), 'The remainder, not a second group.');
        $this->assertSame(1, NotifyMembersStep::$membersCalls, 'members() was not read again.');

        // Every member of the three is now with a worker: the two made
        // first, and the one the resumed claim made.
        $this->assertEquals(
            WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->pluck('input')->all(),
            [['n' => 1], ['n' => 2], ['n' => 3]],
        );
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
