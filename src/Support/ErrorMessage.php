<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * The text of an exception as Laraflow will retain it, before the
 * configured redactor sees it.
 *
 * For most exceptions that is the message. A database exception is the
 * one kind whose message is built *from data*: Laravel formats a
 * QueryException as the driver's error followed by the SQL with every
 * bound value substituted in — and when the failing statement was a
 * checkpoint, a child's creation, or a step's own query, those values
 * are the workflow's input, output, and context. Retaining that
 * message would carry payloads into the `error` columns, the attempt
 * history, the failure events, and the log — every sink the contract
 * says they never reach — and the scrub tier of retention clears
 * payload columns, not error text. The soak suite found exactly this
 * on its first run (Milestone 12).
 *
 * The whole chain is walked, not just the exception in hand, because
 * the message travels: `DeadlockException` is constructed with the
 * QueryException's *message* and keeps it as `previous`, so the values
 * appear in an exception that is not a QueryException at all — which
 * is the form the soak actually produced. Any wrapper that quotes the
 * message it caught is covered by the same replacement.
 *
 * What is kept is the driver's error and the statement with its `?`
 * placeholders: what went wrong, and which statement, without what was
 * in it. The thrown exception itself is untouched.
 */
final class ErrorMessage
{
    /**
     * A chain longer than this is a cycle or a pathology; either way,
     * stop walking it.
     */
    private const MAX_DEPTH = 16;

    public static function of(Throwable $exception): string
    {
        $message = $exception->getMessage();

        for ($link = $exception, $depth = 0; $link !== null && $depth < self::MAX_DEPTH; $link = $link->getPrevious(), $depth++) {
            if (! $link instanceof QueryException) {
                continue;
            }

            $unsafe = $link->getMessage();

            if ($unsafe !== '') {
                $message = str_replace($unsafe, self::withoutBindings($link), $message);
            }
        }

        return $message;
    }

    private static function withoutBindings(QueryException $exception): string
    {
        $previous = $exception->getPrevious();
        $driver = $previous instanceof Throwable ? $previous->getMessage() : 'Database error';

        return "{$driver} (Connection: {$exception->connectionName}, SQL: {$exception->getSql()})";
    }
}
