<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * The state of one piece of remembered work. See docs/idempotency.md.
 */
enum IdempotencyStatus: string
{
    /** The marker is written and the work began; no outcome yet. Found on a later attempt, this is the uncertain state. */
    case Pending = 'pending';

    /** The work completed and its value is durable. */
    case Settled = 'settled';

    /** The work threw an exception that was a known outcome; the next attempt starts over. */
    case Failed = 'failed';
}
