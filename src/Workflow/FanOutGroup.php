<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\FanOutFailedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal The members of one fan-out step, and what their state
 * means for it. The step's machinery asks on creation and on retry,
 * the resolver asks on every heartbeat, and the notifier asks when a
 * member finishes; one class answers all three so they cannot disagree
 * about when a group is done or what to do with a member that is not.
 *
 * Counts first, rows only when a decision needs them: progress is one
 * indexed aggregate, and the member rows are read only to re-deliver
 * the few that need it, to tell a fresh failure from a stale one, or
 * to list the ids a completed group's summary carries.
 */
final class FanOutGroup
{
    /**
     * How many member ids a completed group's summary carries.
     *
     * The summary is a step output: it obeys the payload limit like
     * any other, and a thousand ids is a payload rather than a
     * description. It is also what keeps the cost of deciding about a
     * group independent of the group — reading every id took one more
     * query per page, which a thousand members made visible and three
     * did not (the Milestone 9 scale test).
     */
    public const IDS_IN_SUMMARY = 100;

    /** How many members that did not complete a failure names. */
    private const NAMED_IN_FAILURE = 5;

    /**
     * @param  JoinPolicy  $policy  Named by every caller, with no default: the
     *                              step declares it and both the park and the *resume* must ask
     *                              the same declaration. A default here let the resume path —
     *                              where nearly every join actually decides — silently assume
     *                              WaitAll, so a second policy would have been honoured on the
     *                              way in and ignored on the way out.
     */
    public function __construct(
        private readonly ExecutionRepository $repository,
        private readonly WorkflowExecution $parent,
        private readonly StepExecution $stepRow,
        private readonly JoinPolicy $policy,
    ) {}

    public function progress(): ChildProgress
    {
        return $this->repository->childProgress($this->stepRow->id);
    }

    /**
     * The members in these states — every one when null — linked to
     * this step. Found through the parent filter, which the parent
     * index serves, and narrowed to the step in PHP: a workflow may fan
     * out at several positions.
     *
     * @param  list<ExecutionStatus>|null  $statuses
     * @return list<WorkflowExecution>
     */
    public function members(?array $statuses = null): array
    {
        $members = [];
        $cursor = null;

        do {
            $page = $this->repository->list(
                new ExecutionFilter(status: $statuses, parentId: $this->parent->id, oldestFirst: true),
                ExecutionRepository::MAX_PAGE_SIZE,
                $cursor,
            );

            foreach ($page->executions as $candidate) {
                if ($candidate->parentStepExecutionId === $this->stepRow->id) {
                    $members[] = $candidate;
                }
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $members;
    }

    /**
     * The step's outcome, if the group has one: a summary when every
     * member completed, the failure when every member has rested and
     * not every one completed — or null when the group is still going
     * and the step should park.
     *
     * A member that reads `failed` from *before* this attempt of the
     * step started was charged to the policy already; the attempt's
     * purpose is to resume it, so until it rests again it is still
     * going. Cancelled is final either way: it cannot be resumed, and
     * the group reports the same outcome on every retry.
     *
     * @return array{total: int, completed: int, members: list<int>}|null
     *
     * @throws FanOutFailedException
     */
    public function outcome(ChildProgress $progress): ?array
    {
        // Exhaustive over the enum, so a policy added later is a
        // compile-time obligation here rather than silence: one case
        // ships, and this is the thing it governs.
        return match ($this->policy) {
            JoinPolicy::WaitAll => $this->waitForAll($progress),
        };
    }

    /**
     * The first member ids, at most $limit of them, in creation order.
     * A sample to point at, not a census — but a sample of *this*
     * step's members.
     *
     * The page is of the parent's children and the step is matched in
     * PHP, because that is the filter the parent index serves; so
     * pages are walked until the sample is full or the children run
     * out. Stopping after one page summarised a second fan-out under
     * the same parent as nothing at all, since every member of it was
     * newer than a page of the first group's.
     *
     * @return list<int>
     */
    public function memberIds(int $limit): array
    {
        $ids = [];
        $cursor = null;

        do {
            $page = $this->repository->list(
                new ExecutionFilter(parentId: $this->parent->id, oldestFirst: true),
                ExecutionRepository::MAX_PAGE_SIZE,
                $cursor,
            );

            foreach ($page->executions as $candidate) {
                if ($candidate->parentStepExecutionId !== $this->stepRow->id) {
                    continue;
                }

                $ids[] = $candidate->id;

                if (count($ids) >= $limit) {
                    return $ids;
                }
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $ids;
    }

    /**
     * Hand the members that need a worker back to delivery: a pending
     * one lost its dispatch, and a stale failure is one this attempt
     * exists to resume. Running, retrying, and waiting members are left
     * to their own machinery, recovery's bounds included — re-delivering
     * them here would bypass the abandonment bound. A fresh failure is
     * left alone too: it is this attempt's answer, not its work.
     *
     * @return int How many were handed over.
     */
    public function redeliver(ChildDelivery $delivery, LoggerInterface $logger): int
    {
        $due = array_merge(
            $this->members([ExecutionStatus::Pending]),
            $this->staleFailures(),
        );

        $delivered = 0;

        foreach ($due as $member) {
            try {
                $delivery->deliver($member->id);
                $delivered++;
            } catch (ExecutionNotRunnableException) {
                // Finished, or claimed, between the read and the push.
            } catch (Throwable $failure) {
                // The next heartbeat tries again; the member may also
                // already be running on a worker. Said out loud, or a
                // dead queue becomes an invisible loop.
                $logger->warning('Laraflow could not re-deliver a fan-out member from its parent.', [
                    'parent_execution_id' => $this->parent->id,
                    'member_execution_id' => $member->id,
                    'exception' => $failure::class.': '.$failure->getMessage(),
                ]);
            }
        }

        return $delivered;
    }

    /**
     * @return array{total: int, completed: int, members: list<int>}|null
     *
     * @throws FanOutFailedException
     */
    private function waitForAll(ChildProgress $progress): ?array
    {
        if (! $progress->allFinished()) {
            return null;
        }

        if ($progress->allCompleted()) {
            return [
                'total' => $progress->total(),
                'completed' => $progress->completed(),
                'members' => $this->memberIds(self::IDS_IN_SUMMARY),
            ];
        }

        if ($progress->failed() > 0 && $this->staleFailures() !== []) {
            return null;
        }

        $notCompleted = array_slice(array_map(
            fn (WorkflowExecution $member): int => $member->id,
            $this->members([ExecutionStatus::Failed, ExecutionStatus::Cancelled]),
        ), 0, self::NAMED_IN_FAILURE);

        throw FanOutFailedException::forOutcome($this->stepRow->step, $this->stepRow->position, $progress, $notCompleted);
    }

    /**
     * Failed members whose failure predates this attempt of the step —
     * resumed by it, not charged to it.
     *
     * @return list<WorkflowExecution>
     */
    private function staleFailures(): array
    {
        $since = $this->stepRow->startedAt;

        if ($since === null) {
            return [];
        }

        return array_values(array_filter(
            $this->members([ExecutionStatus::Failed]),
            fn (WorkflowExecution $member): bool => $member->finishedAt !== null && $member->finishedAt < $since,
        ));
    }
}
