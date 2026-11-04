<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A compensation could not run at all. Not the same as a compensation
 * that *threw* — that is an ordinary step failure, retried under the
 * compensated step's policy. This is the pass finding it cannot even
 * begin, which stops it: reaching past an undo that never happened
 * would undo earlier work the failed one may have depended on.
 */
final class CompensationFailedException extends WorkflowException
{
    public static function noLongerCompensatable(string $step, int $position): self
    {
        return new self(sprintf(
            'Step [%s] at position %d completed and is due to be compensated, but its class no longer declares '
            .'compensate(). The code changed between the failure and the compensation pass; nothing can undo it, '
            .'and the pass stops here rather than reaching past it.',
            $step,
            $position,
        ));
    }
}
