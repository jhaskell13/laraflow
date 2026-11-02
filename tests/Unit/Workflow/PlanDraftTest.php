<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use Laraflow\Exceptions\Workflow\PlanMigrationRefusedException;
use Laraflow\Workflow\PlanDraft;
use PHPUnit\Framework\TestCase;

/**
 * The draft a migration edits, tested the way a migration author would
 * test one: from a plain step list, without a database.
 */
class PlanDraftTest extends TestCase
{
    public function test_operations_rewrite_the_tail_and_are_journalled(): void
    {
        $draft = PlanDraft::of('App\\Order', ['A', 'B', 'C', 'D'], frozenBefore: 2, statuses: ['A' => 'completed', 'B' => 'completed']);

        $draft->insertAfter('C', 'X')->remove('D')->replace('C', 'Y')->append('Z');

        $this->assertSame(['A', 'B', 'Y', 'X', 'Z'], $draft->steps());
        $this->assertSame(['Y', 'X', 'Z'], $draft->tail());
        $this->assertSame(2, $draft->frozenBefore());
        $this->assertSame(['insert X after C', 'remove D', 'replace C with Y', 'append Z'], $draft->operations());
    }

    public function test_the_whole_plan_is_the_tail_when_nothing_has_run(): void
    {
        $draft = PlanDraft::of('App\\Order', ['A', 'B']);

        $draft->remove('A')->insertBefore('B', 'X');

        $this->assertSame(['X', 'B'], $draft->tail());
    }

    public function test_a_decided_step_cannot_be_removed_or_replaced(): void
    {
        $draft = PlanDraft::of('App\\Order', ['A', 'B'], frozenBefore: 1, statuses: ['A' => 'completed']);

        try {
            $draft->remove('A');
            $this->fail();
        } catch (PlanMigrationRefusedException $e) {
            $this->assertStringContainsString('remove step [A], which is [completed]', $e->getMessage());
        }

        $this->expectException(PlanMigrationRefusedException::class);
        $draft->replace('A', 'X');
    }

    public function test_a_pending_step_before_a_decided_one_is_frozen_by_it(): void
    {
        // FailurePolicy::Continue can leave a pending row before a
        // completed one; the refusal names the row that froze it.
        $draft = PlanDraft::of('App\\Order', ['A', 'B', 'C'], frozenBefore: 2, statuses: ['A' => 'pending', 'B' => 'completed']);

        try {
            $draft->remove('A');
            $this->fail();
        } catch (PlanMigrationRefusedException $e) {
            $this->assertStringContainsString('[A]. It is still pending, but it sits before [B], which is [completed]', $e->getMessage());
        }
    }

    public function test_nothing_may_be_inserted_before_the_last_decided_step(): void
    {
        $draft = PlanDraft::of('App\\Order', ['A', 'B', 'C'], frozenBefore: 2, statuses: ['B' => 'failed']);

        try {
            $draft->insertBefore('B', 'X');
            $this->fail();
        } catch (PlanMigrationRefusedException $e) {
            $this->assertStringContainsString('insert step [X] before [B], which is [failed]', $e->getMessage());
        }

        try {
            $draft->insertAfter('A', 'X');
            $this->fail();
        } catch (PlanMigrationRefusedException $e) {
            $this->assertStringContainsString('before [B], which is [failed]', $e->getMessage());
        }

        $this->assertSame(['A', 'B', 'X', 'C'], $draft->insertAfter('B', 'X')->steps(), 'Right after the last decided step is the first legal position.');
    }

    public function test_a_plan_that_repeats_a_class_cannot_be_drafted(): void
    {
        $this->expectException(PlanMigrationRefusedException::class);
        $this->expectExceptionMessageMatches('/\\[A\\] would appear more than once/');

        PlanDraft::of('App\\Order', ['A', 'B', 'A']);
    }

    public function test_no_operation_may_introduce_a_repeat(): void
    {
        $draft = PlanDraft::of('App\\Order', ['A', 'B']);

        foreach ([
            fn () => $draft->append('A'),
            fn () => $draft->insertAfter('B', 'A'),
            fn () => $draft->insertBefore('B', 'A'),
            fn () => $draft->replace('B', 'A'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A repeat was introduced.');
            } catch (PlanMigrationRefusedException $e) {
                $this->assertStringContainsString('would appear more than once', $e->getMessage());
            }
        }

        $this->assertSame(['A', 'B'], $draft->steps(), 'A refused operation changes nothing.');
        $this->assertSame([], $draft->operations());
    }

    public function test_an_unknown_step_is_refused(): void
    {
        $this->expectException(PlanMigrationRefusedException::class);
        $this->expectExceptionMessageMatches('/not in this execution\'s plan/');

        PlanDraft::of('App\\Order', ['A'])->remove('Z');
    }
}
