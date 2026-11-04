<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal Tells a waiting parent that its child reached a resting
 * outcome: records the reserved child signal (identity and status, never
 * the child's context) and hands the parent to delivery.
 *
 * Every refusal is a log line, not an error: a parent that is not
 * waiting yet is healed by its own heartbeat, a cancelled or pruned
 * parent needs no answer, and a child's outcome must never be made to
 * fail on its parent's account.
 */
final class ParentNotifier
{
    protected ClockInterface $clock;

    public function __construct(
        protected ExecutionRepository $repository,
        protected LoggerInterface $logger,
        protected ChildDelivery $delivery,
        ClockInterface $clock,
    ) {
        $this->clock = $clock;
    }

    public function childFinished(WorkflowExecution $child): void
    {
        if ($child->parentExecutionId === null) {
            return;
        }

        [$signal, $payload] = $this->signalFor($child);

        if ($signal === null) {
            // One member of a group that is not done: the last one to
            // rest signals the join. If two rest together and both see
            // the group unfinished, the parent's heartbeat sees it done.
            return;
        }

        try {
            $this->repository->recordSignal(
                $child->parentExecutionId,
                $signal,
                $payload,
                $this->clock->now(),
            );
        } catch (SignalRefusedException|ExecutionNotFoundException $refusal) {
            // Not waiting (yet, or any more), cancelled, or pruned. The
            // parent's heartbeat re-checks the child, so nothing is lost.
            $this->logger->info('Laraflow could not signal a parent execution about its child.', [
                'child_execution_id' => $child->id,
                'parent_execution_id' => $child->parentExecutionId,
                'reason' => $refusal->getMessage(),
            ]);

            return;
        } catch (Throwable $failure) {
            // A dropped connection or a lock conflict here must not turn
            // a child that has already durably finished into a failed
            // job; the heartbeat consults the child's record directly.
            $this->logger->warning('Laraflow could not record a child outcome on its parent; the parent heartbeat will pick it up.', [
                'child_execution_id' => $child->id,
                'parent_execution_id' => $child->parentExecutionId,
                'exception' => $failure::class.': '.$failure->getMessage(),
            ]);

            return;
        }

        try {
            $this->delivery->deliver($child->parentExecutionId);
        } catch (Throwable $deliveryFailure) {
            // The signal is durable; laraflow:recover will deliver it.
            $this->logger->warning('Laraflow signalled a parent execution but could not dispatch it.', [
                'parent_execution_id' => $child->parentExecutionId,
                'exception' => $deliveryFailure::class.': '.$deliveryFailure->getMessage(),
            ]);
        }
    }

    /**
     * Which signal this child's resting outcome is, and its payload:
     * the child signal for a sub-workflow's one child, carrying the
     * child's identity and status; the join signal for the last member
     * of a fan-out to rest, carrying the group's counts; and nothing
     * for a member whose siblings are still going.
     *
     * Decided from the parent step's own wait, read from its row, so a
     * member never has to know what kind of step made it. Two queries
     * more than a single child needs; a member is one of many, and the
     * alternative — trying the child signal and reading its refusal —
     * would log a line per member.
     *
     * @return array{string|null, array<string, mixed>}
     */
    protected function signalFor(WorkflowExecution $child): array
    {
        $childSignal = [Wait::CHILD_PREFIX.$child->id, ['execution_id' => $child->id, 'status' => $child->status->value]];

        if ($child->parentStepExecutionId === null) {
            return $childSignal;
        }

        try {
            $parent = $this->repository->find($child->parentExecutionId ?? 0);
        } catch (ExecutionNotFoundException) {
            // Pruned. Nothing to tell, and nothing to say about it.
            return [null, []];
        }

        // A parent that is not parked cannot receive a signal: it has
        // not parked yet (the race the heartbeat heals), or it is
        // cancelled, or it finished without us. Asking anyway costs a
        // refusal and a log line — per member, and a group may be a
        // thousand wide, so cancelling one would be a thousand lines
        // and three thousand queries. The heartbeat covers every case
        // this skips.
        if (! $parent->isWaiting()) {
            return [null, []];
        }

        $stepRow = null;

        foreach ($this->repository->steps($parent) as $candidate) {
            if ($candidate->id === $child->parentStepExecutionId) {
                $stepRow = $candidate;

                break;
            }
        }

        if ($stepRow === null || ! Wait::isJoinSignal($stepRow->waitSignal)) {
            return $childSignal;
        }

        $progress = $this->repository->childProgress($stepRow->id);

        if (! $progress->allFinished()) {
            return [null, []];
        }

        return [(string) $stepRow->waitSignal, ['parent_step_execution_id' => $stepRow->id] + $progress->counts];
    }
}
