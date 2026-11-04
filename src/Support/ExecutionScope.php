<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Which execution this process is running right now — the ambient
 * answer to a question the runner can answer but the layers beneath
 * it cannot. A connector throttling per tenant, or a listener wanting
 * the tenant of the step that is calling it, has no execution in hand;
 * the runner entered one, and this is where it left the record.
 *
 * Entries nest, exactly as the log context's do: a step that runs
 * another workflow in-process pushes a second frame, and leaving it
 * restores the outer execution as current. The log context is told at
 * the same moments, so the two cannot disagree about what is running.
 *
 * Read from durable state, never from the request: `tenantId()` is the
 * tenant the execution was *created for*, which is the one a recovering
 * worker should throttle against, whatever ambient tenant that worker
 * happens to serve.
 */
final class ExecutionScope
{
    /**
     * The executions entered and not yet left, innermost last.
     *
     * @var list<WorkflowExecution>
     */
    private array $frames = [];

    public function __construct(
        private LoggingContext $logging,
    ) {}

    /**
     * Make $execution current for this process until the matching
     * leave(). The runner calls this under the claim, before anything
     * runs, and leave() from a finally.
     */
    public function enter(WorkflowExecution $execution): void
    {
        $this->frames[] = $execution;

        $this->logging->enter($execution);
    }

    /**
     * Narrow the record to the step now running. Nothing here reads it
     * yet beyond the log context; it is passed through so the runner has
     * one collaborator to tell, not two.
     */
    public function enterStep(StepExecution $step): void
    {
        $this->logging->enterStep($step);
    }

    /**
     * Restore whatever was current before the matching enter(). Safe to
     * call with nothing entered, since a finally cannot know whether the
     * try reached the enter.
     */
    public function leave(): void
    {
        if ($this->frames === []) {
            return;
        }

        array_pop($this->frames);

        $this->logging->leave();
    }

    /**
     * The innermost execution being run, or null outside any run — a
     * connector called from a controller, a command, a listener.
     */
    public function current(): ?WorkflowExecution
    {
        $index = count($this->frames) - 1;

        return $index >= 0 ? $this->frames[$index] : null;
    }

    /**
     * The tenant of the current execution, when it was created for one.
     * Null outside a run and for an execution with no tenant: both mean
     * "no tenant to scope by", and callers fall back to the unscoped
     * behaviour rather than inventing one.
     */
    public function tenantId(): ?string
    {
        return $this->current()?->tenantId;
    }

    /** Whether any execution is being run in this process right now. */
    public function active(): bool
    {
        return $this->frames !== [];
    }
}
