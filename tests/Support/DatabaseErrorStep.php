<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use PDOException;

/**
 * A step whose own query failed: Laravel formats the exception's message
 * with every bound value substituted into the SQL, so the message carries
 * the data the statement was writing.
 *
 * With `deadlock` in the context it throws the shape the soak produced
 * instead — a DeadlockException built out of the query exception's
 * message, which is not a QueryException and carries the values anyway.
 */
class DatabaseErrorStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $query = new QueryException(
            'testing',
            'insert into "ledger" ("note", "amount") values (?, ?)',
            ['card tok_SENTINEL123 for customer 42', 1999],
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
        );

        throw $context->get('deadlock', false) === true
            ? new DeadlockException($query->getMessage(), 0, $query)
            : $query;
    }
}
