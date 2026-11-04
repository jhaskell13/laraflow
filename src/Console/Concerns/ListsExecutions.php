<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use Laraflow\Support\JsonReport;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionPage;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @internal One page of executions, rendered the one way — a table with
 * step progress, how many of the matching set the page showed, and the
 * command that continues it — for the commands that list: laraflow:list
 * and laraflow:children. Extracted so the two cannot disagree about what
 * a page looks like.
 *
 * @mixin \Illuminate\Console\Command
 */
trait ListsExecutions
{
    use DisplaysExecutions;
    use ParsesExecutionFilters;
    use ParsesLimit;

    /**
     * Render a page, or say the set is empty. $continue is the command
     * that fetches the next page and $options the filter options it
     * must repeat; the cursor goes between them, where an operator
     * reading the line expects it.
     *
     * @param  array<string, mixed>  $extra  Fields the calling command adds to the document.
     */
    protected function renderPage(
        ExecutionRepository $repository,
        ExecutionFilter $filter,
        ExecutionPage $page,
        string $continue,
        string $options,
        string $empty,
        array $extra = [],
    ): int {
        // One query for the whole page, rather than one per row.
        $progress = $page->isEmpty() ? [] : $repository->stepProgress(array_map(
            fn (WorkflowExecution $execution): int => $execution->id,
            $page->executions,
        ));

        $total = $repository->count($filter);

        $document = $extra + [
            'executions' => array_map(fn (WorkflowExecution $execution): array => JsonReport::execution($execution) + [
                'steps' => $progress[$execution->id] ?? ['total' => 0, 'completed' => 0, 'skipped' => 0],
            ], $page->executions),
            'shown' => $page->count(),
            'total' => $total,
            'next_cursor' => $page->nextCursor,
        ];

        return $this->respond($document, function () use ($page, $progress, $total, $continue, $options, $empty): void {
            if ($page->isEmpty()) {
                $this->components->info($empty);

                return;
            }

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

            // Without the total, a bounded page silently looks like the
            // whole truth — exactly the mistake a listing should not
            // encourage.
            $this->components->info(sprintf('Showing %d of %d matching execution(s).', $page->count(), $total));

            if ($page->nextCursor !== null) {
                // On a raw line, not through the info component: Laravel's
                // EnsurePunctuation appends a full stop, which an operator
                // would paste into their shell as part of the last option.
                $this->components->info('More matching executions exist. Continue with:');
                $this->line('  <fg=gray>'.$continue.' --cursor='.$page->nextCursor.$options.'</>');
                $this->newLine();
            }
        });
    }

    /**
     * Steps done — completed or skipped — out of the plan. Skipped steps
     * are decisions, not work left to do.
     *
     * @param  array<int, array{total: int, completed: int, skipped: int}>  $progress
     */
    protected function stepProgress(array $progress, WorkflowExecution $execution): string
    {
        $counts = $progress[$execution->id] ?? ['total' => 0, 'completed' => 0, 'skipped' => 0];

        return sprintf('%d/%d', $counts['completed'] + $counts['skipped'], $counts['total']);
    }

    /**
     * The filter options of this invocation, so a printed next-page
     * command continues the same query rather than silently widening it.
     *
     * @param  list<string>  $options  The scalar options this command declares that narrow the set.
     */
    protected function repeatedOptions(array $options = ['correlation', 'tenant', 'since', 'until']): string
    {
        $parts = [];

        if ($this->hasOptionDefined('status')) {
            foreach ($this->arrayOption('status') as $status) {
                $parts[] = " --status={$status}";
            }
        }

        if ($this->hasOptionDefined('workflow')) {
            foreach ($this->arrayOption('workflow') as $workflow) {
                $parts[] = ' --workflow='.escapeshellarg($workflow);
            }
        }

        foreach ($options as $option) {
            if ($this->hasOptionDefined($option) && ($value = $this->stringOption($option)) !== null) {
                $parts[] = " --{$option}=".escapeshellarg($value);
            }
        }

        if ($this->hasOptionDefined('oldest') && (bool) $this->option('oldest')) {
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
}
