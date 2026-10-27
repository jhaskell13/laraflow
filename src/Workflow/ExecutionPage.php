<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * One page of executions from ExecutionRepository::list(), with the
 * cursor that fetches the next.
 *
 * Pagination is keyset, not offset: the cursor identifies the last
 * execution on this page, and the next page starts strictly after it.
 * Offset pagination over a table that is being written to skips or
 * repeats rows as earlier pages shift underneath it; a keyset cursor
 * is stable under concurrent inserts and costs the same on page 1000
 * as on page 1.
 *
 * Treat the cursor as opaque: pass back exactly what you were given.
 *
 * @implements IteratorAggregate<int, WorkflowExecution>
 */
readonly class ExecutionPage implements Countable, IteratorAggregate
{
    /**
     * @param  list<WorkflowExecution>  $executions  In the order the filter asked for.
     * @param  string|null  $nextCursor  Null when this is the last page.
     * @param  int  $limit  The page size actually applied, after the
     *                      repository's bound; smaller than requested when the request
     *                      exceeded ExecutionRepository::MAX_PAGE_SIZE.
     */
    public function __construct(
        public array $executions,
        public ?string $nextCursor,
        public int $limit,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    public function isEmpty(): bool
    {
        return $this->executions === [];
    }

    public function count(): int
    {
        return count($this->executions);
    }

    /**
     * @return Traversable<int, WorkflowExecution>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->executions);
    }
}
