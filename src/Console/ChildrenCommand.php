<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\ListsExecutions;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionReader;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * The children of one execution — a sub-workflow's child, a fan-out's
 * members — as a page of the same listing laraflow:list prints, through
 * the same parent filter the dashboard's lineage section reads. The
 * question "what did this fan out into, and how is each doing" was
 * answerable only by filtering a listing by hand until now.
 */
final class ChildrenCommand extends Command
{
    use ListsExecutions;
    use ParsesExecutionId;

    protected $signature = 'laraflow:children
        {execution : The id of the parent execution}
        {--status=* : Only show children with this status; repeatable}
        {--limit=20 : Maximum number of children to show}
        {--cursor= : Continue after a previous page, using its printed cursor}
        {--oldest : Order oldest first — a fan-out\'s member order — instead of newest first}
        {--json : Print one JSON document instead of the table}';

    protected $description = 'List the child executions of a sub-workflow or fan-out parent';

    public function __construct()
    {
        $this->signature = str_replace(
            'Only show children with this status;',
            'Only show children with this status ('.$this->statusList().');',
            $this->signature,
        );

        parent::__construct();
    }

    public function handle(ExecutionReader $repository): int
    {
        try {
            $id = $this->executionIdArgument();
            $parent = $repository->find($id);

            $filter = new ExecutionFilter(
                status: array_map($this->toStatus(...), $this->arrayOption('status')),
                oldestFirst: (bool) $this->option('oldest'),
                parentId: $parent->id,
            );

            $page = $repository->list($filter, $this->limitOption(20), $this->stringOption('cursor'));
        } catch (InvalidArgumentException|WorkflowException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->renderPage(
            $repository,
            $filter,
            $page,
            continue: "laraflow:children {$parent->id}",
            options: $this->repeatedOptions([]),
            empty: $filter->statuses === null
                ? "Execution [{$parent->id}] has no child executions: it is not a sub-workflow or fan-out parent, or its step has not run yet."
                : "Execution [{$parent->id}] has no children in that status.",
            extra: ['parent' => $parent->id],
        );
    }
}
