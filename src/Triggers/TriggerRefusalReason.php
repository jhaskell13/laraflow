<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

/**
 * @experimental Triggers are new in Milestone 10; see docs/triggers.md.
 *
 * Why a trigger request was refused — carried on the TriggerRefused
 * event and written to the log, never to the response. Every
 * verification failure answers the same 401 so a caller learns nothing
 * from the answer; the operator learns which from here.
 */
enum TriggerRefusalReason: string
{
    /** No signature headers, or headers that are not the documented shape. */
    case Unsigned = 'unsigned';

    /** Well-formed, but the timestamp is outside the tolerance. */
    case Stale = 'stale';

    /** Well-formed and current, and no configured key produced it. */
    case InvalidSignature = 'invalid_signature';

    /** Verified, and no such execution exists. */
    case UnknownExecution = 'unknown_execution';

    /** Verified, and the execution is not waiting on that signal. */
    case NotWaiting = 'not_waiting';

    /** Verified, and the body is not a JSON object or the signal name breaks a rule. */
    case InvalidPayload = 'invalid_payload';

    /** Verified, and the payload exceeds the configured limit. */
    case TooLarge = 'too_large';

    /** A named trigger's resolver threw or returned something that is not an intent. */
    case ResolverFailed = 'resolver_failed';
}
