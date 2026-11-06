<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * What recording a signal did: resumed a step parked on
 * that name (the execution is due now), or buffered it in the signal
 * inbox until a step parks on the name. Callers that report the outcome
 * — the console, the dashboard, the trigger endpoint — read this rather
 * than guess from the execution's status, which cannot tell a resumed
 * wait from one still waiting on another name.
 */
final readonly class SignalReceipt
{
    public function __construct(
        public WorkflowExecution $execution,
        public bool $buffered,
    ) {}

    /** The waiting step took the payload; the execution is due now. */
    public function resumed(): bool
    {
        return ! $this->buffered;
    }
}
