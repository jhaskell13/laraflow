<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * An execution's persisted step plan was carried forward to a newer
 * version of its workflow by a declared plan migration, and the
 * rewrite has committed.
 *
 * Lifecycle events carry identifiers and metadata only — never
 * workflow input, step output, or context (see
 * docs/execution-lifecycle.md). Step class names are metadata.
 */
readonly class WorkflowPlanMigrated
{
    /**
     * @param  string  $workflow  Workflow class name.
     * @param  int  $fromVersion  The plan version the execution was on.
     * @param  int  $toVersion  The plan version it is on now.
     * @param  int  $steps  How many steps the plan has after the migration.
     * @param  string|null  $correlationId  Application-supplied identifier
     *                                      recorded on the execution, or null if none was supplied.
     * @param  string|null  $tenantId  Application-supplied tenant identifier,
     *                                 or null if none was supplied.
     */
    public function __construct(
        public int $executionId,
        public string $workflow,
        public int $fromVersion,
        public int $toVersion,
        public int $steps,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
