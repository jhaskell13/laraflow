<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * How the children of one parent step are doing: a count per status,
 * read in one indexed query by ExecutionRepository::childProgress().
 *
 * The value object a fan-out's join decides on. It carries counts and
 * nothing else — no ids, no payloads — because a thousand members is
 * the case it exists for, and a join that needed to read them to know
 * whether it was done could not afford to heartbeat.
 */
final readonly class ChildProgress
{
    /**
     * @param  array<string, int>  $counts  Children per status value; a status with no children is absent.
     */
    public function __construct(
        public int $parentStepExecutionId,
        public array $counts,
    ) {}

    /** A parent step with no children at all. */
    public static function none(int $parentStepExecutionId): self
    {
        return new self($parentStepExecutionId, []);
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function of(ExecutionStatus $status): int
    {
        return $this->counts[$status->value] ?? 0;
    }

    public function completed(): int
    {
        return $this->of(ExecutionStatus::Completed);
    }

    public function failed(): int
    {
        return $this->of(ExecutionStatus::Failed);
    }

    public function cancelled(): int
    {
        return $this->of(ExecutionStatus::Cancelled);
    }

    /**
     * Children that have reached a resting outcome — completed, failed,
     * or cancelled: the states that record finished_at.
     */
    public function finished(): int
    {
        $finished = 0;

        foreach ($this->counts as $status => $count) {
            if (ExecutionStatus::tryFrom($status)?->isFinished() ?? false) {
                $finished += $count;
            }
        }

        return $finished;
    }

    /**
     * Children still going: pending, running, retrying, or waiting. A
     * status this version does not know counts as unfinished — the
     * cautious reading, since a join that resumed on an unknown state
     * would be guessing.
     */
    public function unfinished(): int
    {
        return $this->total() - $this->finished();
    }

    /**
     * Whether every child has reached a resting outcome. False for a
     * step with no children: a join with nothing to join is not done,
     * it is empty, and the step decides what that means.
     */
    public function allFinished(): bool
    {
        return $this->total() > 0 && $this->unfinished() === 0;
    }

    /** Whether every child completed — the join's one happy outcome. */
    public function allCompleted(): bool
    {
        return $this->total() > 0 && $this->completed() === $this->total();
    }
}
