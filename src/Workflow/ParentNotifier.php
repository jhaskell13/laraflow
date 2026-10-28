<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Support\Clock;
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
class ParentNotifier
{
    protected ClockInterface $clock;

    public function __construct(
        protected ExecutionRepository $repository,
        protected LoggerInterface $logger,
        protected ?ChildDelivery $delivery = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new Clock;
    }

    public function childFinished(WorkflowExecution $child): void
    {
        if ($child->parentExecutionId === null) {
            return;
        }

        $signal = Wait::CHILD_PREFIX.$child->id;

        try {
            $this->repository->recordSignal(
                $child->parentExecutionId,
                $signal,
                ['execution_id' => $child->id, 'status' => $child->status->value],
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
            $this->delivery?->deliver($child->parentExecutionId);
        } catch (Throwable $deliveryFailure) {
            // The signal is durable; laraflow:recover will deliver it.
            $this->logger->warning('Laraflow signalled a parent execution but could not dispatch it.', [
                'parent_execution_id' => $child->parentExecutionId,
                'exception' => $deliveryFailure::class.': '.$deliveryFailure->getMessage(),
            ]);
        }
    }
}
