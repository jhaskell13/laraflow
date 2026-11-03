<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * @internal How far a compensation pass got, which decides what the
 * runner does next. Three outcomes rather than a boolean, because
 * "did not finish" hides two states that need opposite handling —
 * one where the claim is already released and something will deliver
 * the execution again, and one where nothing ever will.
 */
enum CompensationOutcome
{
    /** Every undo ran. The execution rests on the failure that began the pass. */
    case Complete;

    /**
     * An undo is scheduled to be tried again: the claim is released
     * and the due-retry scan delivers it. The execution must be left
     * alone.
     */
    case Scheduled;

    /**
     * An undo will not be tried again — its retries are exhausted, or
     * nothing can run it. The pass stops rather than reaching past it,
     * and the execution still comes to rest: it was left claimed and
     * compensating, which nothing could claim and no operator could
     * cancel (Milestone 9 audit).
     */
    case Stopped;
}
