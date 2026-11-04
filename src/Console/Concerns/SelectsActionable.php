<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Clock\ClockInterface;

/**
 * @internal What a bulk cancel or re-run selects: the operator's filter,
 * narrowed to the statuses the operation can act on.
 *
 * Before this, both commands selected every row the filter matched,
 * oldest first, and a completed or already-cancelled row was refused or
 * skipped — and then selected again, first, by the "run the same
 * command again to continue" the report recommended. A tenant with a
 * hundred completed executions ahead of its failed ones never got past
 * them (Milestone 10 audit). Narrowing in the query means a row that
 * cannot be acted on is never selected, `matched` counts only what the
 * operation can touch, and a second run makes progress.
 *
 * @mixin \Illuminate\Console\Command
 */
trait SelectsActionable
{
    /**
     * Completed and cancelled are terminal: never claimable, never
     * cancellable. Everything else is something both operations can at
     * least try — `failed` included, because re-running it is the
     * documented recovery and laraflow:execute is the operator's
     * explicit opt-in to it.
     *
     * @return list<ExecutionStatus>
     */
    protected function actionableStatuses(): array
    {
        return array_values(array_filter(
            ExecutionStatus::cases(),
            fn (ExecutionStatus $status): bool => $status !== ExecutionStatus::Completed && $status !== ExecutionStatus::Cancelled,
        ));
    }

    /**
     * $filter narrowed to the actionable statuses, intersected with any
     * --status the operator gave. A --status naming only terminal
     * statuses is refused by name rather than answered with "nothing
     * matched", which would read as though the filter were wrong rather
     * than the operation.
     *
     * @throws InvalidArgumentException
     */
    protected function narrowToActionable(ExecutionFilter $filter, string $verb): ExecutionFilter
    {
        $actionable = $this->actionableStatuses();

        if ($filter->statuses === null) {
            return $filter->withStatus($actionable);
        }

        $kept = array_values(array_filter($filter->statuses, fn (ExecutionStatus $status): bool => in_array($status, $actionable, true)));

        if ($kept === []) {
            throw new InvalidArgumentException(sprintf(
                'A bulk %s cannot act on %s executions: completed and cancelled are terminal. --status may name: %s.',
                $verb,
                implode(' or ', array_map(fn (ExecutionStatus $status): string => $status->value, $filter->statuses)),
                implode(', ', array_map(fn (ExecutionStatus $status): string => $status->value, $actionable)),
            ));
        }

        return $filter->withStatus($kept);
    }

    /**
     * The statuses of $original that narrowing dropped, for the report
     * to name — a --status=failed,completed run should say it ignored
     * the completed half, not leave the operator wondering.
     *
     * @return list<string>
     */
    protected function ignoredStatuses(ExecutionFilter $original): array
    {
        if ($original->statuses === null) {
            return [];
        }

        $actionable = $this->actionableStatuses();

        return array_values(array_map(
            fn (ExecutionStatus $status): string => $status->value,
            array_filter($original->statuses, fn (ExecutionStatus $status): bool => ! in_array($status, $actionable, true)),
        ));
    }

    /**
     * Whether a worker still owns $execution: running or compensating
     * under a claim younger than `stale_after` — the same test claim()
     * and requestCancellation() apply, read from the same setting, so a
     * dry run predicts what the real run will meet.
     */
    protected function liveClaim(WorkflowExecution $execution): bool
    {
        if (! $execution->isRunning() && ! $execution->isCompensating()) {
            return false;
        }

        return $execution->claimedAt !== null && $execution->claimedAt > $this->staleBefore();
    }

    protected function now(): DateTimeImmutable
    {
        return $this->laravel->make(ClockInterface::class)->now();
    }

    protected function staleBefore(): DateTimeImmutable
    {
        $seconds = $this->laravel->make(RunnerOptions::class)->staleAfterSeconds;

        return $this->now()->modify("-{$seconds} seconds");
    }

    /**
     * The continuation line. A run that left rows as they were — refused,
     * or a cancellation merely requested from a live worker — will meet
     * them first again, oldest first, so "run it again" would loop; it
     * says so and names the way past them instead.
     */
    protected function continuationHint(int $total, int $handled, int $leftInPlace): ?string
    {
        if ($total <= $handled) {
            return null;
        }

        if ($leftInPlace > 0) {
            return sprintf(
                'More matching executions exist, but %d row(s) in this batch were left as they were and would be selected first again. '
                .'Narrow the filter past them (--since, --status) to continue.',
                $leftInPlace,
            );
        }

        return 'More matching executions exist; run the same command again to continue.';
    }
}
