<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * The query builder behind every Laraflow model, so that an instant
 * bound into a WHERE, an UPDATE, or an INSERT is written as its UTC
 * wall-clock — the convention every Laraflow column is stored in
 * (docs/compatibility.md, "Stored timestamps").
 *
 * Laravel turns a DateTimeInterface binding into a string with the
 * object's *own* wall-clock and no offset (Connection::prepareBindings).
 * Eloquent's casts never see a query-builder update or a comparison,
 * so `where('next_retry_at', '<=', $now)` with a New York `$now`
 * compared New York wall-clock against whatever the column held — and
 * in the repeated autumn hour two different instants render to the
 * same wall-clock, so "is it due" had two answers. Converting here,
 * at the one hook every binding passes (addBinding() for predicates,
 * cleanBindings() for insert and update values, both through
 * castBinding()), covers the repositories' raw comparisons and writes
 * without each call site having to remember. Nested and sub-queries
 * are built with newQuery(), which returns this class again.
 */
final class UtcQueryBuilder extends Builder
{
    // All three required, where the parent defaults the last two to
    // the connection's: the model hands over exactly the connection's
    // own, and newQuery() passes this builder's along.
    public function __construct(ConnectionInterface $connection, Grammar $grammar, Processor $processor)
    {
        parent::__construct($connection, $grammar, $processor);
    }

    public function castBinding($value)
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format($this->grammar->getDateFormat());
        }

        return parent::castBinding($value);
    }
}
