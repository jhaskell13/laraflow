<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;

/**
 * The read half of execution storage: what application code, the
 * console, and the dashboard use to look at executions.
 *
 * Split out of ExecutionRepository before 1.0 so that the part worth
 * freezing is small. Everything here is a query — no method claims,
 * writes, or changes what a worker will do next — and every query is
 * bounded: lists are keyset-paginated under MAX_PAGE_SIZE, progress and
 * history are one query for many rows. Type-hint this, not the whole
 * repository, wherever code only reads; it resolves to the same
 * singleton (docs/public-api.md).
 */
interface ExecutionReader
{
    /**
     * The most executions list() will ever return in one page, whatever
     * limit the caller asked for. Observability tooling that can ask
     * for an unbounded result is observability tooling that can take an
     * application down; the bound lives in the contract so no caller
     * can be surprised by an implementation that forgot it.
     */
    public const MAX_PAGE_SIZE = 500;

    /**
     * @throws ExecutionNotFoundException
     */
    public function find(
        int $id
    ): WorkflowExecution;

    /**
     * @return StepExecution[] ordered by position.
     */
    public function steps(
        WorkflowExecution $execution
    ): array;

    /**
     * Attempt history for one step, ordered by attempt number ascending.
     *
     * @return StepAttempt[]
     */
    public function attempts(
        StepExecution $step
    ): array;

    /**
     * Attempt history for every step of one execution in a single
     * query, ordered by step position then attempt number. The same rows
     * attempts() returns per step, without one query per step.
     *
     * @return StepAttempt[]
     */
    public function attemptHistory(
        WorkflowExecution $execution
    ): array;

    /**
     * One page of executions matching $filter, ordered by id (newest
     * first unless the filter says otherwise), paginated by keyset.
     *
     * $limit is clamped to [1, MAX_PAGE_SIZE]; the page reports the
     * limit actually applied. $cursor is the `nextCursor` of a previous
     * page and is opaque to callers; an implementation may reject a
     * cursor it did not issue.
     *
     * Every filter criterion must be served from an index — this is the
     * stable listing API for applications and consoles, and it must stay
     * bounded on a high-volume table.
     *
     * @throws InvalidArgumentException for a malformed cursor.
     */
    public function list(
        ExecutionFilter $filter,
        int $limit = 50,
        ?string $cursor = null
    ): ExecutionPage;

    /**
     * How many executions match $filter. Ordering and pagination do not
     * apply; the count is of the whole matching set.
     */
    public function count(
        ExecutionFilter $filter
    ): int;

    /**
     * Step counts for several executions in one query: how many steps
     * each has, and how many have completed.
     *
     * A listing that shows progress would otherwise call steps() per
     * row. The ids are those of executions the caller already holds;
     * ids with no steps are absent from the result rather than present
     * as zero, so a caller can tell "no plan" from "nothing done".
     *
     * @param  list<int>  $ids
     * @return array<int, array{total: int, completed: int, skipped: int}> keyed by execution id.
     */
    public function stepProgress(
        array $ids
    ): array;

    /**
     * How the children of one parent step are doing — a count per
     * status — in one query, served by the index on
     * parent_step_execution_id.
     *
     * A fan-out's join asks this on every heartbeat, and a group may be
     * a thousand wide; reading the members to count them would make
     * the heartbeat cost what it exists to avoid. Children are found by
     * the parent *step*, not the parent execution: a workflow may fan
     * out at several positions, and each join must see only its own.
     *
     * Returns an empty progress — total zero — for a step with no
     * children, rather than throwing: "none yet" is an ordinary state
     * of a step that is about to create them.
     */
    public function childProgress(
        int $parentStepExecutionId
    ): ChildProgress;
}
