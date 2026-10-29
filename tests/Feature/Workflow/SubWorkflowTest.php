<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Laraflow\Exceptions\Workflow\ChildWorkflowFailedException;
use Laraflow\Exceptions\Workflow\WorkflowDepthExceededException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\HookedParentWorkflow;
use Laraflow\Tests\Support\HookedSubStep;
use Laraflow\Tests\Support\ParentWorkflow;
use Laraflow\Tests\Support\RecursiveWorkflow;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The composition contract (docs/advanced-workflows.md): a sub-workflow
 * is a wait on the child's reserved signal, two linkage columns, and
 * the child's outcome as the parent step's own.
 */
class SubWorkflowTest extends TestCase
{
    public function test_the_parent_creates_a_linked_child_and_parks_on_its_signal(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-28 10:00:00');

        $parent = $this->runner()->run(new ParentWorkflow, [
            'subsidiary_id' => 7,
            'correlation' => true,
        ], new \Laraflow\Workflow\ExecutionOptions(correlationId: 'req-9', tenantId: 't-1'));

        $this->assertTrue($parent->isWaiting());

        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();
        $this->assertSame($parent->id, $child->parent_execution_id);
        $this->assertSame(['subsidiary_id' => 7], $child->input);
        $this->assertSame('req-9', $child->correlation_id, 'Identifiers are inherited.');
        $this->assertSame('t-1', $child->tenant_id);

        $stepRow = StepExecutionModel::query()
            ->where('workflow_execution_id', $parent->id)
            ->where('position', 1)
            ->firstOrFail();
        $this->assertSame($stepRow->id, $child->parent_step_execution_id);
        $this->assertSame("laraflow.child.{$child->id}", $stepRow->wait_signal);

        // The park heartbeats rather than waiting indefinitely: if the
        // child's signal is ever lost, the parent checks on it.
        $this->assertSame(
            '2026-10-28 10:05:00',
            WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->next_retry_at?->format('Y-m-d H:i:s'),
        );

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $child->id,
        );
    }

    public function test_the_childs_completion_resumes_the_parent_with_the_outcome_as_output(): void
    {
        Queue::fake();
        $parent = $this->runner()->run(new ParentWorkflow);
        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();

        // A worker runs the child; its final release signals the parent.
        $finished = $this->runner()->execute($child->id, new TwoStepWorkflow);
        $this->assertTrue($finished->isCompleted());

        $parentRow = WorkflowExecutionModel::query()->whereKey($parent->id)->sole();
        $this->assertNotNull($parentRow->next_retry_at, 'The signal made the parent due.');

        // The signal also asked for delivery of the parent itself.
        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $parent->id,
        );

        $resumed = $this->runner()->execute($parent->id, new ParentWorkflow);

        $this->assertTrue($resumed->isCompleted());
        // assertEquals, not assertSame: MySQL's binary JSON format
        // reorders object keys, so the payload's key order does not
        // survive the column round-trip on every engine.
        $this->assertEquals(
            ['execution_id' => $child->id, 'status' => 'completed'],
            StepExecutionModel::query()
                ->where('workflow_execution_id', $parent->id)
                ->where('position', 1)->sole()->output,
            'The child outcome — identity and status, never its context — is the step output.',
        );
    }

    public function test_a_failed_child_fails_the_parent_step_and_a_retry_resumes_the_same_child(): void
    {
        Queue::fake();
        ParentWorkflow::$policy = new StepPolicy(maxAttempts: 2, baseDelaySeconds: 30, jitter: 0.0);
        StepTwo::$failTimes = 1;
        Date::setTestNow('2026-10-28 10:00:00');

        $parent = $this->runner()->run(new ParentWorkflow);
        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();

        try {
            $this->runner()->execute($child->id, new TwoStepWorkflow);
            $this->fail('Expected the child failure.');
        } catch (RuntimeException) {
            // The child is durably failed; its outcome signalled the parent.
        }

        $retrying = $this->runner()->execute($parent->id, new ParentWorkflow);
        $this->assertTrue($retrying->isRetrying(), 'The child failure is charged to the parent step policy.');

        Date::setTestNow('2026-10-28 10:00:30');
        $parked = $this->runner()->execute($parent->id, new ParentWorkflow);

        $this->assertTrue($parked->isWaiting());
        $this->assertSame(
            1,
            WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->count(),
            'The retry resumed the same child; no sibling was spawned.',
        );

        // And the resumed child finishes the job from its checkpoints.
        $this->runner()->execute($child->id, new TwoStepWorkflow);
        $resumed = $this->runner()->execute($parent->id, new ParentWorkflow);
        $this->assertTrue($resumed->isCompleted());
        // StepOne ran once in the parent and once in the child's first
        // attempt; the child's resume skipped its completed checkpoint.
        $this->assertSame(2, \Laraflow\Tests\Support\StepOne::$runs, 'The child re-ran only its failed step.');
    }

    public function test_the_heartbeat_re_parks_and_redelivers_an_unfinished_child(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-28 10:00:00');

        $parent = $this->runner()->run(new ParentWorkflow);
        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();

        // The heartbeat comes due with the child never having run — its
        // dispatch was lost, say. The parent checks, re-delivers, parks.
        Date::setTestNow('2026-10-28 10:05:00');
        $stillWaiting = $this->runner()->execute($parent->id, new ParentWorkflow);

        $this->assertTrue($stillWaiting->isWaiting());
        $this->assertSame(
            '2026-10-28 10:10:00',
            WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->next_retry_at?->format('Y-m-d H:i:s'),
            'A fresh heartbeat was scheduled.',
        );
        // The second push targeted the child, not something else.
        $deliveries = 0;
        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job) use ($child, &$deliveries): bool {
            if ($job->executionId === $child->id) {
                $deliveries++;
            }

            return true;
        });
        $this->assertSame(2, $deliveries, 'The lost dispatch was re-delivered to the child.');
    }

    public function test_a_recursive_workflow_is_refused_at_the_depth_bound(): void
    {
        Queue::fake();
        config(['laraflow.workflows.max_depth' => 2]);

        // Depth 1: the root. Its child (depth 2) is allowed; the
        // grandchild would be depth 3 and must be refused at creation.
        $root = $this->runner()->run(new RecursiveWorkflow);
        $this->assertTrue($root->isWaiting());

        $childId = WorkflowExecutionModel::query()->where('parent_execution_id', $root->id)->sole()->id;

        try {
            $this->runner()->execute($childId, new RecursiveWorkflow);
            $this->fail('Expected the depth bound.');
        } catch (WorkflowDepthExceededException $exception) {
            $this->assertStringContainsString('max_depth', $exception->getMessage());
        }

        $this->assertSame(
            2,
            WorkflowExecutionModel::query()->count(),
            'The refused grandchild was never created.',
        );
    }

    public function test_cancelling_the_parent_cascades_to_unfinished_children(): void
    {
        Queue::fake();
        $parent = $this->runner()->run(new ParentWorkflow);
        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();

        $cancelled = $this->runner()->cancel($parent->id, 'tear it all down');

        $this->assertTrue($cancelled->isCancelled());
        $this->assertSame(
            ExecutionStatus::Cancelled->value,
            WorkflowExecutionModel::query()->whereKey($child->id)->sole()->status,
            'The pending child was cancelled with its parent.',
        );
        $this->assertSame('tear it all down', WorkflowExecutionModel::query()->whereKey($child->id)->sole()->cancel_reason);
    }

    public function test_a_cancelled_child_fails_the_parent_step_terminally(): void
    {
        Queue::fake();
        $parent = $this->runner()->run(new ParentWorkflow);
        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();

        $this->runner()->cancel($child->id, 'child no longer wanted');

        try {
            $this->runner()->execute($parent->id, new ParentWorkflow);
            $this->fail('Expected the child outcome to fail the parent.');
        } catch (ChildWorkflowFailedException $exception) {
            $this->assertStringContainsString('cancelled', $exception->getMessage());
        }

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status,
        );
        $this->assertSame(
            StepStatus::Failed->value,
            StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole()->status,
        );
    }

    public function test_children_are_listable_by_the_parent_filter(): void
    {
        Queue::fake();
        $parent = $this->runner()->run(new ParentWorkflow);

        $page = $this->app->make(ExecutionRepository::class)->list(new ExecutionFilter(parentId: $parent->id));

        $this->assertCount(1, $page->executions);
        $this->assertSame(TwoStepWorkflow::class, $page->executions[0]->workflow);
        $this->assertSame($parent->id, $page->executions[0]->parentExecutionId);
    }

    public function test_a_stale_child_failure_payload_cannot_be_recharged_after_a_retry(): void
    {
        // The child's failure signalled the parent (payload on the step
        // row) and was charged; the parent's retry re-parks. The stale
        // payload must not survive into the new wait, and the heartbeat
        // must resume the re-delivered child rather than charging the
        // same failure twice.
        Queue::fake();
        ParentWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 30, jitter: 0.0);
        StepTwo::$failTimes = 1;
        Date::setTestNow('2026-10-28 10:00:00');

        $parent = $this->runner()->run(new ParentWorkflow);
        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();

        try {
            $this->runner()->execute($child->id, new TwoStepWorkflow);
            $this->fail('Expected the child failure.');
        } catch (RuntimeException) {
            // Charged to the parent step below.
        }

        $this->runner()->execute($parent->id, new ParentWorkflow);   // charges attempt 1, schedules retry
        Date::setTestNow('2026-10-28 10:00:30');
        $parked = $this->runner()->execute($parent->id, new ParentWorkflow); // retry re-parks

        $this->assertTrue($parked->isWaiting());
        $parentStep = StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole();
        $this->assertNull($parentStep->output, 'The charged failure payload must not survive into the new wait.');

        // Heartbeat with the child still failed (its re-delivery is
        // sitting on the faked queue): the failure predates this
        // attempt, so the parent re-delivers and parks — no new charge.
        Date::setTestNow('2026-10-28 10:05:30');
        $stillWaiting = $this->runner()->execute($parent->id, new ParentWorkflow);

        $this->assertTrue($stillWaiting->isWaiting(), 'A pre-attempt failure is resumed, not recharged.');
        $this->assertSame(2, StepExecutionModel::query()->where('workflow_execution_id', $parent->id)->where('position', 1)->sole()->attempts);
    }

    public function test_a_cancellation_honoured_at_a_boundary_cascades_to_children(): void
    {
        // cancel() lands while the sub-workflow step runs — after
        // cancel()'s own cascade swept past (finding nothing), before
        // the child exists. The park boundary honours the request; the
        // cascade must run there too, or the just-created child keeps
        // working for a cancelled parent.
        Queue::fake();
        $runner = $this->runner();

        HookedSubStep::$beforeCreate = function () use ($runner): void {
            $parent = WorkflowExecutionModel::query()->where('workflow', HookedParentWorkflow::class)->sole();
            $runner->cancel($parent->id, 'raced the cascade');
        };

        $result = $runner->run(new HookedParentWorkflow);

        $this->assertTrue($result->isCancelled());
        $this->assertSame(
            ExecutionStatus::Cancelled->value,
            WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole()->status,
            'The boundary cascade must catch a child created mid-race.',
        );
    }

    public function test_a_pruned_child_fails_the_parent_as_missing_rather_than_hanging(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-28 10:00:00');
        $parent = $this->runner()->run(new ParentWorkflow);
        $child = WorkflowExecutionModel::query()->where('workflow', TwoStepWorkflow::class)->sole();

        WorkflowExecutionModel::query()->whereKey($child->id)->delete();

        Date::setTestNow('2026-10-28 10:05:00');

        try {
            $this->runner()->execute($parent->id, new ParentWorkflow);
            $this->fail('Expected the missing child to fail the step.');
        } catch (ChildWorkflowFailedException $exception) {
            $this->assertStringContainsString('no longer exists', $exception->getMessage());
        }

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->whereKey($parent->id)->sole()->status,
        );
    }

    public function test_a_pruned_ancestor_does_not_break_the_depth_walk(): void
    {
        Queue::fake();
        $root = $this->runner()->run(new RecursiveWorkflow);
        $childId = WorkflowExecutionModel::query()->where('parent_execution_id', $root->id)->sole()->id;

        // Retention pruned the long-finished root; the live child can
        // still spawn its own sub-workflow without a bewildering
        // not-found error from the depth walk.
        WorkflowExecutionModel::query()->whereKey($root->id)->delete();

        $waiting = $this->runner()->execute($childId, new RecursiveWorkflow);

        $this->assertTrue($waiting->isWaiting());
        $this->assertSame(
            1,
            WorkflowExecutionModel::query()->where('parent_execution_id', $childId)->count(),
            'The grandchild was created; the walk stopped at the pruned link.',
        );
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
