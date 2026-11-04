<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * @internal A failure rebuilt from what was recorded about it, for a
 * compensation pass resumed on a later claim.
 *
 * The exception object a pass began with is gone by then. Its message
 * and class were written to the execution when the pass started, so
 * this carries both — reporting the recorded class as its own, which
 * is what keeps `error_class` stable across a pass that spans claims.
 */
final class RecordedFailure extends WorkflowException
{
    public function __construct(string $message, private readonly string $recordedClass)
    {
        parent::__construct($message);
    }

    /** The class of the failure this stands for, not this class. */
    public function recordedClass(): string
    {
        return $this->recordedClass;
    }
}
