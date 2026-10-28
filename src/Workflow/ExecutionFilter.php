<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Criteria for listing and counting executions through
 * ExecutionRepository::list() and ::count().
 *
 * Every filter here corresponds to an indexed column, so a query built
 * from any combination stays bounded on a high-volume table. That is a
 * deliberate constraint on what this class offers: a filter Laraflow
 * cannot serve from an index is a filter it does not expose.
 *
 * Time windows are half-open — `after` is inclusive, `before` is
 * exclusive — so consecutive windows neither overlap nor leave a gap,
 * which is what reporting and retention both need.
 *
 * This class is pure: it describes what to match and knows nothing
 * about persistence. Validation happens at construction so a broken
 * filter fails where it is written rather than inside a scheduled job.
 */
readonly class ExecutionFilter
{
    /** @var list<string>|null Workflow class names; null matches every workflow. */
    public ?array $workflows;

    /** @var list<ExecutionStatus>|null Null matches every status. */
    public ?array $statuses;

    public ?string $correlationId;

    public ?string $tenantId;

    /**
     * @param  string|list<string>|null  $workflow  One workflow class, or several.
     * @param  ExecutionStatus|list<ExecutionStatus>|null  $status  One status, or several.
     * @param  string|null  $correlationId  Exact match; blank means unfiltered.
     * @param  string|null  $tenantId  Exact match; blank means unfiltered.
     * @param  DateTimeImmutable|null  $createdAfter  Inclusive lower bound on creation.
     * @param  DateTimeImmutable|null  $createdBefore  Exclusive upper bound on creation.
     * @param  DateTimeImmutable|null  $finishedAfter  Inclusive lower bound on finished_at;
     *                                                 implicitly restricts to terminal executions.
     * @param  DateTimeImmutable|null  $finishedBefore  Exclusive upper bound on finished_at;
     *                                                  implicitly restricts to terminal executions.
     * @param  bool  $oldestFirst  Order by id ascending instead of the default descending.
     * @param  int|string|null  $parentId  Only children of this execution (sub-workflows).
     */
    public function __construct(
        string|array|null $workflow = null,
        ExecutionStatus|array|null $status = null,
        ?string $correlationId = null,
        ?string $tenantId = null,
        public ?DateTimeImmutable $createdAfter = null,
        public ?DateTimeImmutable $createdBefore = null,
        public ?DateTimeImmutable $finishedAfter = null,
        public ?DateTimeImmutable $finishedBefore = null,
        public bool $oldestFirst = false,
        public int|string|null $parentId = null,
    ) {
        $this->workflows = self::normalizeWorkflows($workflow);
        $this->statuses = self::normalizeStatuses($status);
        $this->correlationId = self::normalizeIdentifier($correlationId);
        $this->tenantId = self::normalizeIdentifier($tenantId);

        if ($createdAfter !== null && $createdBefore !== null && $createdAfter >= $createdBefore) {
            throw new InvalidArgumentException(
                'createdAfter must be earlier than createdBefore; the window is empty.'
            );
        }

        if ($finishedAfter !== null && $finishedBefore !== null && $finishedAfter >= $finishedBefore) {
            throw new InvalidArgumentException(
                'finishedAfter must be earlier than finishedBefore; the window is empty.'
            );
        }
    }

    /**
     * A filter that matches every execution.
     */
    public static function all(): self
    {
        return new self;
    }

    /**
     * The same filter narrowed to $status, keeping every other
     * criterion — so a caller counting one status at a time is
     * counting within the same scope each time.
     *
     * @param  ExecutionStatus|list<ExecutionStatus>|null  $status
     */
    public function withStatus(ExecutionStatus|array|null $status): self
    {
        return new self(
            workflow: $this->workflows,
            status: $status,
            correlationId: $this->correlationId,
            tenantId: $this->tenantId,
            createdAfter: $this->createdAfter,
            createdBefore: $this->createdBefore,
            finishedAfter: $this->finishedAfter,
            finishedBefore: $this->finishedBefore,
            oldestFirst: $this->oldestFirst,
            parentId: $this->parentId,
        );
    }

    /**
     * The same filter in the opposite order.
     */
    public function withOldestFirst(bool $oldestFirst = true): self
    {
        return new self(
            workflow: $this->workflows,
            status: $this->statuses,
            correlationId: $this->correlationId,
            tenantId: $this->tenantId,
            createdAfter: $this->createdAfter,
            createdBefore: $this->createdBefore,
            finishedAfter: $this->finishedAfter,
            finishedBefore: $this->finishedBefore,
            oldestFirst: $oldestFirst,
            parentId: $this->parentId,
        );
    }

    /**
     * True when no criterion is set: list() returns everything (newest
     * first) and count() counts the whole table.
     */
    public function isEmpty(): bool
    {
        return $this->workflows === null
            && $this->statuses === null
            && $this->correlationId === null
            && $this->tenantId === null
            && $this->createdAfter === null
            && $this->createdBefore === null
            && $this->finishedAfter === null
            && $this->finishedBefore === null
            && $this->parentId === null;
    }

    /**
     * @param  string|array<mixed>|null  $workflow
     * @return list<string>|null
     */
    protected static function normalizeWorkflows(string|array|null $workflow): ?array
    {
        if ($workflow === null) {
            return null;
        }

        $workflows = [];

        foreach (is_string($workflow) ? [$workflow] : $workflow as $class) {
            if (! is_string($class) || trim($class) === '') {
                throw new InvalidArgumentException(
                    'Each workflow filter must be a non-empty class name, got '.get_debug_type($class).'.'
                );
            }

            $workflows[] = $class;
        }

        return $workflows === [] ? null : array_values(array_unique($workflows));
    }

    /**
     * @param  ExecutionStatus|array<mixed>|null  $status
     * @return list<ExecutionStatus>|null
     */
    protected static function normalizeStatuses(ExecutionStatus|array|null $status): ?array
    {
        if ($status === null) {
            return null;
        }

        $statuses = [];

        foreach ($status instanceof ExecutionStatus ? [$status] : $status as $case) {
            if (! $case instanceof ExecutionStatus) {
                throw new InvalidArgumentException(
                    'Each status filter must be an ExecutionStatus, got '.get_debug_type($case).'.'
                );
            }

            $statuses[$case->value] = $case;
        }

        return $statuses === [] ? null : array_values($statuses);
    }

    /**
     * Blank means unfiltered, mirroring ExecutionOptions where blank
     * means absent: a filter for the empty string would match nothing,
     * which is never what a caller forwarding an optional input wants.
     */
    protected static function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
