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
 * Addressing by class name is what makes a repeated class ambiguous,
 * so a plan that repeats one cannot be migrated, and no operation may
 * introduce a repeat.
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

    /** @var list<string> Every operation applied, for the log. */
    private array $operations = [];

    /**
     * @param  list<string>  $steps  The plan, in position order.
     * @param  int  $frozenBefore  The first position a migration may touch:
     *                             one past the highest-positioned step that is not pending.
     * @param  array<string, string>  $statuses  Step class => persisted status, for refusal messages.
     */
    private function __construct(
        private readonly string $workflow,
        array $steps,
        private readonly int $frozenBefore,
        private readonly array $statuses,
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

        foreach ($stepExecutions as $step) {
            $steps[] = $step->step;
            $statuses[$step->step] = $step->status->value;

            // The frozen prefix runs to the last decided row, whatever
            // its status: under FailurePolicy::Continue a pending row
            // can sit before a completed one, and it is frozen too —
            // the tail a migration rewrites has to be contiguous or
            // renumbering it would disturb rows that did run.
            if (! $step->isPending()) {
                $frozenBefore = $step->position + 1;
            }
        }

        return self::of($workflow, $steps, $frozenBefore, $statuses);
    }

    /**
     * Build a draft from a plain step list — how a migration is
     * unit-tested, without a container, a database, or an execution.
     * $frozenBefore of zero means nothing has run.
     *
     * @param  list<string>  $steps
     * @param  array<string, string>  $statuses
     *
     * @throws PlanMigrationRefusedException when a step class appears more than once.
     */
    public static function of(string $workflow, array $steps, int $frozenBefore = 0, array $statuses = []): self
    {
        $seen = [];

        foreach ($steps as $step) {
            if (isset($seen[$step])) {
                throw PlanMigrationRefusedException::duplicateStep($workflow, $step);
            }

            $seen[$step] = true;
        }

        return new self($workflow, $steps, $frozenBefore, $statuses);
    }

    /** Add a step at the end of the plan. */
    public function append(string $step): self
    {
        $this->assertNew($step);
        $this->steps[] = $step;
        $this->operations[] = "append {$step}";

        return $this;
    }

    /** Add a step immediately after an existing one. */
    public function insertAfter(string $existing, string $step): self
    {
        $this->assertNew($step);
        $at = $this->positionOf($existing) + 1;

        if ($at < $this->frozenBefore) {
            throw PlanMigrationRefusedException::insertionInFrozenPrefix($this->workflow, $step, $this->lastDecided(), $this->statuses[$this->lastDecided()] ?? 'decided');
        }

        array_splice($this->steps, $at, 0, [$step]);
        $this->operations[] = "insert {$step} after {$existing}";

        return $this;
    }

    /** Add a step immediately before an existing one. */
    public function insertBefore(string $existing, string $step): self
    {
        $this->assertNew($step);
        $at = $this->positionOf($existing);

        if ($at < $this->frozenBefore) {
            throw PlanMigrationRefusedException::insertionInFrozenPrefix($this->workflow, $step, $this->lastDecided(), $this->statuses[$this->lastDecided()] ?? 'decided');
        }

        array_splice($this->steps, $at, 0, [$step]);
        $this->operations[] = "insert {$step} before {$existing}";

        return $this;
    }

    /** Remove a step that has not run. */
    public function remove(string $step): self
    {
        $at = $this->assertInTail($step, 'remove');

        array_splice($this->steps, $at, 1);
        $this->operations[] = "remove {$step}";

        return $this;
    }

    /** Replace a step that has not run with another. */
    public function replace(string $old, string $new): self
    {
        $this->assertNew($new);
        $this->steps[$this->assertInTail($old, 'replace')] = $new;
        $this->operations[] = "replace {$old} with {$new}";

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

    /**
     * The part of the plan a migration may have changed: every step
     * from the first undecided position on. What the repository
     * rewrites.
     *
     * @return list<string>
     */
    public function tail(): array
    {
        return array_slice($this->steps(), $this->frozenBefore);
    }

    /** The first position a migration may touch — where tail() begins. */
    public function frozenBefore(): int
    {
        return $this->frozenBefore;
    }

    /**
     * Every operation applied to this draft, in order, as the log
     * records them.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->operations;
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
     * A step being added must not already be in the plan: the next
     * migration would have no way to say which one it meant.
     *
     * @throws PlanMigrationRefusedException
     */
    private function assertNew(string $step): void
    {
        if (in_array($step, $this->steps, true)) {
            throw PlanMigrationRefusedException::duplicateStep($this->workflow, $step);
        }
    }

    /**
     * The position of a step a migration may change, or a refusal
     * naming what froze it.
     *
     * @throws PlanMigrationRefusedException
     */
    private function assertInTail(string $step, string $operation): int
    {
        $at = $this->positionOf($step);

        if ($at >= $this->frozenBefore) {
            return $at;
        }

        $status = $this->statuses[$step] ?? 'decided';

        // A pending row inside the frozen prefix is frozen by the
        // decided row after it, not by anything it did itself; the
        // refusal has to say which row that is.
        if ($status === StepStatus::Pending->value) {
            $decided = $this->lastDecided();

            throw PlanMigrationRefusedException::frozenByLaterStep($this->workflow, $step, $operation, $decided, $this->statuses[$decided] ?? 'decided');
        }

        throw PlanMigrationRefusedException::frozenStep($this->workflow, $step, $operation, $status);
    }

    /** The last step the execution has decided — the end of the frozen prefix. */
    private function lastDecided(): string
    {
        return $this->steps[$this->frozenBefore - 1] ?? '(none)';
    }
}
