<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Lists executions through the public ExecutionRepository query API —
 * the same filters and keyset pagination an application gets. The
 * command holds no query knowledge of its own and touches no Eloquent
 * model, so anything it can show, application code can show too.
 */
class ListCommand extends Command
{
    use DisplaysExecutions;
    use ParsesExecutionFilters;

    protected $signature = 'laraflow:list
        {--status=* : Only show executions with this status; repeatable}
        {--workflow=* : Only show executions created from this workflow class; repeatable}
        {--correlation= : Only show executions with this correlation id}
        {--tenant= : Only show executions for this tenant id}
        {--since= : Only show executions created at or after this time (inclusive)}
        {--until= : Only show executions created before this time (exclusive)}
        {--limit=20 : Maximum number of executions to show}
        {--cursor= : Continue after a previous page, using its printed cursor}
        {--oldest : Order oldest first instead of newest first}';

    protected $description = 'List workflow executions';

    /**
     * The valid statuses are listed from the enum rather than written
     * out, so help text cannot drift from the cases that actually exist.
     */
    public function __construct()
    {
        $this->signature = str_replace(
            'Only show executions with this status;',
            'Only show executions with this status ('.$this->statusList().');',
            $this->signature,
        );

        parent::__construct();
    }

    public function handle(ExecutionRepository $repository): int
    {
        try {
            $filter = $this->filterFromOptions((bool) $this->option('oldest'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $page = $repository->list(
                $filter,
                (int) $this->option('limit'),
                $this->stringOption('cursor'),
            );
        } catch (InvalidArgumentException $exception) {
            // A malformed cursor: the repository refuses to guess, and
            // neither does the command.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($page->isEmpty()) {
            $this->components->info(
                $filter->isEmpty()
                    ? 'No executions found. Try: laraflow:demo'
                    : 'No executions match that filter.'
            );

            return self::SUCCESS;
        }

        // One query for the whole page, rather than one per row.
        $progress = $repository->stepProgress(array_map(
            fn (WorkflowExecution $execution): int|string => $execution->id,
            $page->executions,
        ));

        $this->table(
            ['Id', 'Workflow', 'Status', 'Steps', 'Started', 'Finished', 'Error'],
            array_map(fn (WorkflowExecution $execution): array => [
                $execution->id,
                class_basename($execution->workflow),
                $this->coloredStatus($execution->status->value),
                $this->stepProgress($progress, $execution),
                $this->formatDate($execution->startedAt),
                $this->formatDate($execution->finishedAt),
                $execution->error !== null ? $this->formatValue($execution->error, 40) : '',
            ], $page->executions),
        );

        $this->summarize($repository, $filter, $page->count(), $page->nextCursor);

        return self::SUCCESS;
    }

    /**
     * How much of the matching set this page showed, and how to see the
     * rest. Without the total, a bounded page silently looks like the
     * whole truth — which is exactly the mistake a listing command
     * should not encourage an operator to make.
     */
    protected function summarize(
        ExecutionRepository $repository,
        ExecutionFilter $filter,
        int $shown,
        ?string $cursor,
    ): void {
        $total = $repository->count($filter);

        $this->components->info(sprintf(
            'Showing %d of %d matching execution(s).',
            $shown,
            $total,
        ));

        if ($cursor !== null) {
            // The command goes on a raw line, not through the info
            // component: Laravel's EnsurePunctuation mutator appends a
            // full stop to component output, which an operator would
            // copy straight into their shell as part of the last option.
            $this->components->info('More matching executions exist. Continue with:');
            $this->line('  <fg=gray>laraflow:list --cursor='.$cursor.$this->repeatedOptions().'</>');
            $this->newLine();
        }
    }

    /**
     * The filter options of the current invocation, so the printed
     * next-page command continues the same query rather than silently
     * widening it.
     */
    protected function repeatedOptions(): string
    {
        $parts = [];

        foreach ($this->arrayOption('status') as $status) {
            $parts[] = " --status={$status}";
        }

        foreach ($this->arrayOption('workflow') as $workflow) {
            $parts[] = ' --workflow='.escapeshellarg($workflow);
        }

        foreach (['correlation', 'tenant', 'since', 'until'] as $option) {
            if (($value = $this->stringOption($option)) !== null) {
                $parts[] = " --{$option}=".escapeshellarg($value);
            }
        }

        if ((bool) $this->option('oldest')) {
            $parts[] = ' --oldest';
        }

        // Compared against the signature's own default rather than a
        // repeated literal, so changing one cannot leave the other
        // quietly printing a redundant option.
        $default = $this->getDefinition()->getOption('limit')->getDefault();
        $default = is_scalar($default) ? (string) $default : '';

        if ((string) $this->option('limit') !== $default) {
            $parts[] = ' --limit='.(int) $this->option('limit');
        }

        return implode('', $parts);
    }

    /**
     * Completed steps out of the plan.
     *
     * @param  array<string, array{total: int, completed: int}>  $progress
     */
    protected function stepProgress(array $progress, WorkflowExecution $execution): string
    {
        $counts = $progress[(string) $execution->id] ?? ['total' => 0, 'completed' => 0];

        return sprintf('%d/%d', $counts['completed'], $counts['total']);
    }
}
