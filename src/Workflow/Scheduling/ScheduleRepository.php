<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use Closure;
use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\ScheduleNotFoundException;
use Laraflow\Exceptions\Workflow\TickAlreadyClaimedException;
use Laraflow\Workflow\WorkflowExecution;

/**
 * Durable storage for schedules and their ticks.
 *
 * Implementations must keep the guarantees in docs/schedules.md: the
 * row is a cache of the declaration and never the authority; a tick is
 * claimed by a unique insert on (schedule, instant), so exactly one of
 * any number of concurrent claimants succeeds; and the tick row, the
 * execution it starts, and the schedule's advanced high-water mark
 * commit in one transaction. Every instant passed in and handed back is
 * UTC.
 */
interface ScheduleRepository
{
    /**
     * Write the declaration to its row, creating the row if this is the
     * first time the class has been seen — with `lastTickAt = $now`,
     * because a new schedule never backfills. Updates never touch
     * `lastTickAt` or `pausedAt`; `declaredAt` advances to $now, which
     * is what tells a live row from an orphaned one.
     */
    public function sync(ScheduleDefinition $definition, DateTimeImmutable $now): ScheduledWorkflow;

    /**
     * Every schedule row, declared and orphaned alike, in creation
     * order. Bounded by how many schedules an application registers,
     * not by history — history is the ticks.
     *
     * @return list<ScheduledWorkflow>
     */
    public function all(): array;

    public function find(string $workflow): ?ScheduledWorkflow;

    /**
     * Claim $tickAt for $schedule by inserting its row — the
     * once-per-tick guarantee — run $start inside the same transaction
     * when the outcome starts an execution, and advance the schedule's
     * `lastTickAt` to $tickAt (forward only, so concurrent claims of
     * different instants cannot move it backwards). A lost insert race
     * rolls the whole transaction back, the execution included, and is
     * reported as TickAlreadyClaimedException — the designed outcome
     * for every claimant but one.
     *
     * @param  (Closure(): WorkflowExecution)|null  $start  Creates the execution
     *                                                      this tick starts; null when the outcome starts none
     *                                                      (an overlap skip).
     * @param  int  $missed  Due instants that passed unclaimed before this
     *                       tick, recorded on it.
     *
     * @throws TickAlreadyClaimedException
     */
    public function claimTick(
        ScheduledWorkflow $schedule,
        DateTimeImmutable $tickAt,
        TickOutcome $outcome,
        ?Closure $start,
        DateTimeImmutable $claimedAt,
        int $missed = 0,
        ?DateTimeImmutable $missedFrom = null,
        ?int $overlappedExecutionId = null,
    ): ScheduleTick;

    /**
     * The most recent tick of $scheduleId that started an execution —
     * what overlap suppression consults.
     */
    public function lastFiredTick(int $scheduleId): ?ScheduleTick;

    /**
     * Executions started by ticks that are still `pending` after the
     * cutoff — never dispatched, or dispatched before it and never
     * claimed — oldest first. A scheduled execution is never work the
     * application is deliberately holding, which is why the scheduler
     * may re-dispatch these where the general pending scan must stay
     * opt-in (docs/schedules.md, "A lost dispatch heals").
     *
     * @return list<int>
     */
    public function redispatchCandidates(DateTimeImmutable $before, int $limit): array;

    /**
     * Pause: the schedule stays synchronised and names no tick until
     * resumed. Idempotent — pausing a paused schedule keeps the
     * original pausedAt.
     *
     * @throws ScheduleNotFoundException
     */
    public function pause(string $workflow, DateTimeImmutable $at): ScheduledWorkflow;

    /**
     * Resume: `lastTickAt` jumps to $at, so the paused interval is
     * never caught up — paused means "do not run", not "run later".
     * Resuming a schedule that is not paused changes nothing.
     *
     * @throws ScheduleNotFoundException
     */
    public function resume(string $workflow, DateTimeImmutable $at): ScheduledWorkflow;
}
