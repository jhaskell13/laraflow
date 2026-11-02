<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

/**
 * Marks an exception whose being thrown does not mean the work it
 * interrupted failed to take effect — a client timeout on a request
 * the provider may have processed, a connection lost after the bytes
 * were sent.
 *
 * WorkflowContext::remember() treats an ordinary exception as a known
 * outcome: the record is marked failed and the next attempt runs the
 * work again. An exception implementing this interface leaves the
 * record pending instead, so the next attempt takes the uncertain path
 * — reconcile, or raise — rather than repeating a side effect that may
 * already have happened. The counterpart of SuggestsRetryDelay: an
 * exception telling the machinery something only it knows.
 *
 * See docs/idempotency.md.
 */
interface IndicatesUncertainOutcome {}
