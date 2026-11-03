<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laraflow\Events\WorkflowFanOutStarted;
use Laraflow\Exceptions\Workflow\FanOutTooWideException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\CrashingExecutionRepository;
use Laraflow\Tests\Support\FailingAfterDelivery;
use Laraflow\Tests\Support\FanOutWorkflow;
use Laraflow\Tests\Support\NotifyMembersStep;
use Laraflow\Tests\Support\OneStepWorkflow;
use Laraflow\Tests\Support\SecondFanOutStep;
use Laraflow\Tests\Support\SimulatedWorkerCrash;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoFanOutWorkflow;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ChildDelivery;
use Laraflow\Workflow\ChildWorkflows;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\FanOutWorkflows;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;

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

    /**
     * The summary's member ids are "the members of this step", and a
     * workflow may fan out at more than one position. The ids were
     * read from a single page of the parent's children, oldest first,
     * and then filtered to the step in PHP — so a second group whose
     * members are all newer than one page of the first group's
     * summarised *nothing*, silently, while reporting the right counts.
     */
    public function test_a_second_fan_out_under_the_same_parent_summarises_its_own_members(): void
    {
        // The first group fills a page on its own, so the second
        // group's members are nowhere in it.
        NotifyMembersStep::$inputs = array_map(fn (int $n): array => ['n' => $n], range(1, 100));
        NotifyMembersStep::$workflow = OneStepWorkflow::class;
        SecondFanOutStep::$inputs = [['m' => 1], ['m' => 2]];
        TwoFanOutWorkflow::$steps = [NotifyMembersStep::class, SecondFanOutStep::class];

        $parent = $this->runner()->run(new TwoFanOutWorkflow);

        $this->assertTrue($parent->isCompleted());

        $second = StepExecutionModel::query()
            ->where('workflow_execution_id', $parent->id)
            ->where('position', 1)
            ->sole();

        $expected = WorkflowExecutionModel::query()
            ->where('parent_step_execution_id', $second->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertCount(2, $expected, 'The second group has two members.');
        $this->assertSame(2, $second->output['total'] ?? null);
        $this->assertSame($expected, $second->output['members'] ?? null, 'Its summary names its own members, not nothing.');
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
        Date::setTestNow('2026-11-02 10:00:00');

        // The worker dies creating the second member: the first exists
        // and was delivered, the second and third were never made, and
        // — as after a real death — nothing after the boundary is
        // persisted, the failure included. The parent keeps its claim.
        $real = $this->app->make(ExecutionRepository::class);
        $this->app->instance(ExecutionRepository::class, new CrashingExecutionRepository($real, 'create', 3, 'before'));

        try {
            $this->runner()->run(new FanOutWorkflow);
            $this->fail('The repository was meant to take the worker down.');
        } catch (SimulatedWorkerCrash) {
        }

        $parent = WorkflowExecutionModel::query()->whereNull('parent_execution_id')->sole();
        $this->assertSame(ExecutionStatus::Running->value, $parent->status, 'Dead mid-step: still claimed, nothing recorded.');
        $this->assertSame(1, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count(), 'Exactly the member made before the death.');
        $this->assertCount(3, StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole()->input['members'] ?? [], 'The declaration was durable before the first member.');
        $this->assertSame(1, NotifyMembersStep::$membersCalls);

        // A live worker, after the claim has gone stale, takes over. It
        // reads the declaration from the row, counts what exists, and
        // makes only the remainder.
        $this->app->instance(ExecutionRepository::class, $real);
        foreach ([WorkflowRunner::class, FanOutWorkflows::class, ChildWorkflows::class, WorkflowDispatcher::class] as $singleton) {
            $this->app->forgetInstance($singleton);
        }
        Date::setTestNow('2026-11-02 10:06:00');

        $resumed = $this->runner()->execute($parent->id, new FanOutWorkflow);

        $this->assertTrue($resumed->isWaiting(), 'Parked on the join with the group complete.');
        $this->assertSame(3, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count(), 'The remainder, not a second group.');
        $this->assertSame(1, NotifyMembersStep::$membersCalls, 'members() was not read again.');
        $this->assertEquals(
            [['n' => 1], ['n' => 2], ['n' => 3]],
            WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->pluck('input')->all(),
            'The remainder is the declared tail, in order.',
        );
    }

    public function test_a_push_that_fails_is_contained_and_said_and_creation_goes_on(): void
    {
        Queue::fake();
        $logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $logger);

        // The broker refuses the second member's push. The member exists
        // — its creation committed — and the heartbeat re-delivers a
        // pending member; what must not happen is the whole fan-out
        // failing, or the group being created twice.
        $this->app->instance(ChildDelivery::class, new FailingAfterDelivery($this->app->make(ChildDelivery::class), dieOn: 2));

        $parent = $this->runner()->run(new FanOutWorkflow);

        $this->assertTrue($parent->isWaiting(), 'The fan-out parked; one bad push did not fail it.');
        $this->assertSame(3, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count(), 'Every member was created.');
        Queue::assertPushed(ExecuteWorkflowJob::class, 2);
        $this->assertTrue(
            $logger->hasRecordContaining('warning', 'could not deliver a fan-out member'),
            'Said out loud, or a dead queue is an invisible loop.',
        );
    }

    public function test_a_fan_out_over_nothing_completes_rather_than_parking(): void
    {
        Queue::fake();

        // "Notify every subscriber", with none: an ordinary day, not a
        // broken group. Parking would wait out a heartbeat and then
        // fail blaming retention for members that never existed.
        NotifyMembersStep::$inputs = [];

        $parent = $this->runner()->run(new FanOutWorkflow);

        $this->assertTrue($parent->isCompleted());
        $this->assertSame(0, WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->count());

        $output = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole()->output;
        // assertEquals, not assertSame: MySQL's JSON type stores an
        // object with its keys sorted and hands them back that way, so
        // the summary reads as total/members/completed there and
        // total/completed/members everywhere else. The shape is the
        // claim; the key order is the engine's business.
        $this->assertEquals(['total' => 0, 'completed' => 0, 'members' => []], $output);
        Queue::assertNothingPushed();
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
