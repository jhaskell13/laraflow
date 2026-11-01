<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Exceptions\Workflow\PlanMigrationRefusedException;

/**
 * The mutable step list a plan migration edits.
 *
 * A migration receives a draft of the execution's persisted plan and
 * changes it by step class name rather than by position, because
 * positions shift as the draft changes and a migration written against
 * them would mean different things depending on what had already run.
 *
 * Only the *undecided tail* may change: every step after the last one
 * this execution has already decided. A pending step row has no
 * output, no attempt history, and no context contribution, so it can
 * be renumbered, removed, or replaced without rewriting history, while
 * every other row is the record of something that happened. Operations
 * that would reach into the frozen prefix are refused rather than
 * silently narrowed. See docs/workflow-versioning.md.
 */
final class PlanDraft
{
    /** @var array<int, string> */
    private array $steps;

    /**
     * @param  list<string>  $steps  The persisted plan, in position order.
     * @param  int  $frozenBefore  The first position a migration may touch:
     *                             one past the highest-positioned step that is not pending.
     * @param  array<string, string>  $statuses  Step class => persisted status, for refusal messages.
     */
    private function __construct(
        private readonly string $workflow,
        array $steps,
        private readonly int $frozenBefore,
        private readonly array $statuses = [],
    ) {
        $this->steps = $steps;
    }

    /**
     * Build a draft from the execution's persisted step rows.
     *
     * @param  StepExecution[]  $stepExecutions  In position order.
     *
     * @throws PlanMigrationRefusedException when a step class appears more than once.
     */
    public static function fromSteps(string $workflow, array $stepExecutions): self
    {
        $steps = [];
        $statuses = [];
        $frozenBefore = 0;

        foreach ($stepExecutions as $index => $step) {
            $steps[] = $step->step;
            $statuses[$step->step] = $step->status->value;

            // The frozen prefix runs to the last decided row, whatever
            // its status: under FailurePolicy::Continue a pending row
            // can sit before a completed one, and it is frozen too —
            // the tail a migration rewrites has to be contiguous or
            // renumbering it would disturb rows that did run.
            if (! $step->isPending()) {
                $frozenBefore = $index + 1;
            }
        }

        $duplicate = self::firstDuplicate($steps);

        if ($duplicate !== null) {
            throw PlanMigrationRefusedException::duplicateStep($workflow, $duplicate);
        }

        return new self($workflow, $steps, $frozenBefore, $statuses);
    }

    /** Add a step at the end of the plan. */
    public function append(string $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    /** Add a step immediately after an existing one. */
    public function insertAfter(string $existing, string $step): self
    {
        $at = $this->positionOf($existing) + 1;

        if ($at < $this->frozenBefore) {
            throw PlanMigrationRefusedException::insertionInFrozenPrefix($this->workflow, $step, $this->steps[$at]);
        }

        array_splice($this->steps, $at, 0, [$step]);

        return $this;
    }

    /** Add a step immediately before an existing one. */
    public function insertBefore(string $existing, string $step): self
    {
        $at = $this->positionOf($existing);

        if ($at < $this->frozenBefore) {
            throw PlanMigrationRefusedException::insertionInFrozenPrefix($this->workflow, $step, $existing);
        }

        array_splice($this->steps, $at, 0, [$step]);

        return $this;
    }

    /** Remove a step that has not run. */
    public function remove(string $step): self
    {
        $at = $this->assertInTail($step, 'remove');

        array_splice($this->steps, $at, 1);

        return $this;
    }

    /** Replace a step that has not run with another. */
    public function replace(string $old, string $new): self
    {
        $this->steps[$this->assertInTail($old, 'replace')] = $new;

        return $this;
    }

    /**
     * The plan as it now stands.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return array_values($this->steps);
    }

    /** The first position a migration may touch. */
    public function frozenBefore(): int
    {
        return $this->frozenBefore;
    }

    /**
     * @throws PlanMigrationRefusedException
     */
    private function positionOf(string $step): int
    {
        $at = array_search($step, $this->steps, true);

        if ($at === false) {
            throw PlanMigrationRefusedException::unknownStep($this->workflow, $step);
        }

        return $at;
    }

    /**
     * The position of a step a migration may change, or a refusal
     * naming the status that froze it.
     *
     * @throws PlanMigrationRefusedException
     */
    private function assertInTail(string $step, string $operation): int
    {
        $at = $this->positionOf($step);

        if ($at < $this->frozenBefore) {
            throw PlanMigrationRefusedException::frozenStep(
                $this->workflow,
                $step,
                $operation,
                // A pending row inside the frozen prefix is frozen by
                // the decided row after it, not by its own status.
                $this->statuses[$step] ?? 'decided',
            );
        }

        return $at;
    }

    /**
     * @param  list<string>  $steps
     */
    private static function firstDuplicate(array $steps): ?string
    {
        $seen = [];

        foreach ($steps as $step) {
            if (isset($seen[$step])) {
                return $step;
            }

            $seen[$step] = true;
        }

        return null;
    }
}
