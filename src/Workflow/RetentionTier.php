<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * The three things retention can do to finished work, in increasing
 * order of destructiveness.
 *
 * Each tier is *self-limiting*: once applied to an execution, that
 * execution stops matching the tier's scan. Without that property a
 * scheduled run would rescan the same oldest executions every time,
 * exhaust its limit on work already done, and never reach anything
 * newer. How each one achieves it differs, which is why the scan takes
 * the tier rather than a generic filter:
 *
 * - Delete removes the row, so it cannot match again.
 * - ScrubPayloads sets `payloads_scrubbed_at`, and its scan requires
 *   that column to be null.
 * - PruneAttempts leaves no mark on the execution, so its scan requires
 *   that attempt rows still exist — served by the index the attempts
 *   table has carried since Milestone 3.
 */
enum RetentionTier: string
{
    /**
     * Clear the workflow input, the context snapshot, and every step's
     * output, keeping the execution record itself as an audit trail of
     * what ran and when.
     *
     * Never applied to `failed` executions: re-executing one is the
     * documented recovery path, and it needs its input and context to
     * resume. Delete them explicitly if you want them gone.
     */
    case ScrubPayloads = 'scrub_payloads';

    /**
     * Delete the append-only attempt history, keeping the execution and
     * its steps. The step row still carries the latest attempt's
     * status, error, and attempt count, so this costs diagnostics for
     * older attempts and nothing else — resumability included, because
     * the retry budget reads the step's counter, not this table.
     */
    case PruneAttempts = 'prune_attempts';

    /**
     * Delete the execution and everything belonging to it.
     */
    case Delete = 'delete';
}
