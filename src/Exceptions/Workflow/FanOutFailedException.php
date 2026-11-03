<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use Laraflow\Workflow\ChildProgress;

/**
 * A fan-out's members all reached a resting outcome and not every one
 * completed. The step fails with the group's shape — how many ended in
 * each state, and the first few that did not complete — subject to the
 * step's retry policy, where a retry re-delivers the members that did
 * not complete so each resumes from its own checkpoints. Cancelled
 * members cannot be resumed; a group containing one reports the same
 * outcome on every retry until an operator intervenes.
 */
class FanOutFailedException extends WorkflowException
{
    /**
     * @param  list<int>  $notCompleted  The first few member ids that did not complete.
     */
    public static function forOutcome(string $step, int $position, ChildProgress $progress, array $notCompleted): self
    {
        $shape = [];

        foreach ($progress->counts as $status => $count) {
            $shape[] = "{$count} {$status}";
        }

        return new self(sprintf(
            'Step [%s] at position %d fanned out to %d member execution(s), of which %d completed (%s). '
            .'Members that did not complete: [%s]%s.',
            $step,
            $position,
            $progress->total(),
            $progress->completed(),
            implode(', ', $shape),
            implode(', ', $notCompleted),
            count($notCompleted) < $progress->total() - $progress->completed() ? ', and more' : '',
        ));
    }

    public static function noMembers(string $step, int $position, string $signal): self
    {
        return new self(sprintf(
            'Step [%s] at position %d is parked on join signal [%s], but no member execution is linked to it. '
            .'The members were pruned by retention, or the row has been altered.',
            $step,
            $position,
            $signal,
        ));
    }

    public static function malformedSignal(string $step, int $position, string $signal): self
    {
        return new self(sprintf(
            'Step [%s] at position %d is parked on join signal [%s], which does not name a step. '
            .'Only the fan-out machinery writes this column; the row has been altered.',
            $step,
            $position,
            $signal,
        ));
    }

    public static function notDeclared(string $step, int $position): self
    {
        return new self(sprintf(
            'Step [%s] at position %d has member executions but no recorded declaration of them. '
            .'Only the fan-out machinery writes a step\'s input; the row has been altered.',
            $step,
            $position,
        ));
    }
}
