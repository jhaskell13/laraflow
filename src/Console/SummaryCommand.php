<?php

declare(strict_types=1);

namespace Laraflow\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\StepExecution;
use Psr\Clock\ClockInterface;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * One screen answering "is anything wrong right now?": how many
 * executions are in each status for a given scope, and the three
 * conditions that warrant a look — retries that are due, steps past
 * their deadline, and the oldest execution still unfinished.
 *
 * Deliberately not a per-workflow breakdown. That needs a GROUP BY,
 * which the repository contract does not expose and should not gain for
 * a console view; `--workflow=` scopes the same numbers to one workflow
 * using an indexed filter instead.
 */
class SummaryCommand extends Command
{
    use DisplaysExecutions;
    use ParsesExecutionFilters;

    protected $signature = 'laraflow:summary
        {--workflow=* : Only count executions created from this workflow class; repeatable}
        {--correlation= : Only count executions with this correlation id}
        {--tenant= : Only count executions for this tenant id}
        {--since= : Only count executions created at or after this time (inclusive)}
        {--until= : Only count executions created before this time (exclusive)}';

    protected $description = 'Summarise workflow executions by status, and surface work needing attention';

    public function handle(ExecutionRepository $repository, ClockInterface $clock): int
    {
        try {
            $filter = $this->filterFromOptions();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $total = $repository->count($filter);

        if ($total === 0) {
            $this->components->info(
                $filter->isEmpty()
                    ? 'No executions found. Try: laraflow:demo'
                    : 'No executions match that filter.'
            );

            return self::SUCCESS;
        }

        $this->displayCounts($repository, $filter, $total);

        return $this->displayAttention($repository, $filter, $clock);
    }

    /**
     * One count() per status, each an indexed query. Statuses come from
     * the enum, so a status added later appears here without this
     * command being touched.
     */
    protected function displayCounts(
        ExecutionRepository $repository,
        ExecutionFilter $filter,
        int $total,
    ): void {
        $rows = [];

        foreach (ExecutionStatus::cases() as $status) {
            $count = $repository->count($this->scopedTo($filter, $status));

            $rows[] = [
                $this->coloredStatus($status->value),
                $count,
                $total > 0 ? sprintf('%.1f%%', $count / $total * 100) : '—',
            ];
        }

        $rows[] = ['<options=bold>total</>', $total, '100.0%'];

        $this->newLine();
        $this->table(['Status', 'Executions', 'Share'], $rows);
    }

    /**
     * The three conditions worth an operator's attention. Exits non-zero
     * when any is present, so this command can be used as a health
     * check by a scheduler or a deploy gate.
     */
    protected function displayAttention(
        ExecutionRepository $repository,
        ExecutionFilter $filter,
        ClockInterface $clock,
    ): int {
        $now = $clock->now();
        $attention = false;

        $due = $repository->dueForRetry($now, ExecutionRepository::MAX_PAGE_SIZE);

        if ($due !== []) {
            $attention = true;
            $oldest = $due[0];

            $this->components->warn(sprintf(
                '%d retr%s due now; the most overdue is execution [%s], due %s. Deliver them with: laraflow:recover',
                count($due),
                count($due) === 1 ? 'y is' : 'ies are',
                $oldest->id,
                $this->formatDate($oldest->nextRetryAt),
            ));
        }

        $overdue = $this->overdueSteps($repository, $filter, $now);

        if ($overdue !== []) {
            $attention = true;

            $this->components->warn(sprintf(
                '%d execution(s) have a running step past its deadline: %s. '
                .'Laraflow does not interrupt running PHP; these are bounded by your queue worker timeout.',
                count($overdue),
                implode(', ', array_map(
                    fn (array $row): string => "[{$row['id']}] ".class_basename($row['step']),
                    array_slice($overdue, 0, 5),
                )),
            ));
        }

        $unfinished = $repository->list(
            new ExecutionFilter(
                workflow: $filter->workflows,
                status: [ExecutionStatus::Pending, ExecutionStatus::Running, ExecutionStatus::Retrying],
                correlationId: $filter->correlationId,
                tenantId: $filter->tenantId,
                createdAfter: $filter->createdAfter,
                createdBefore: $filter->createdBefore,
                oldestFirst: true,
            ),
            limit: 1,
        );

        if (! $unfinished->isEmpty()) {
            $execution = $unfinished->executions[0];

            $this->components->info(sprintf(
                'Oldest unfinished execution: [%s] %s, created %s.',
                $execution->id,
                class_basename($execution->workflow),
                $this->formatDate($execution->createdAt),
            ));
        }

        if (! $attention) {
            $this->components->info('Nothing needs attention: no retries due and no step past its deadline.');
        }

        $this->newLine();

        return $attention ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Running executions whose current step has passed its deadline.
     *
     * Bounded by one page of running executions: a healthy installation
     * has few, and an unhealthy one does not need an exhaustive list to
     * know it is unhealthy.
     *
     * @return list<array{id: int|string, step: string}>
     */
    protected function overdueSteps(
        ExecutionRepository $repository,
        ExecutionFilter $filter,
        DateTimeImmutable $now,
    ): array {
        $running = $repository->list(
            new ExecutionFilter(
                workflow: $filter->workflows,
                status: ExecutionStatus::Running,
                correlationId: $filter->correlationId,
                tenantId: $filter->tenantId,
                createdAfter: $filter->createdAfter,
                createdBefore: $filter->createdBefore,
            ),
            limit: ExecutionRepository::MAX_PAGE_SIZE,
        );

        $overdue = [];

        foreach ($running as $execution) {
            foreach ($repository->steps($execution) as $step) {
                if ($this->isOverdue($step, $now)) {
                    $overdue[] = ['id' => $execution->id, 'step' => $step->step];

                    break;
                }
            }
        }

        return $overdue;
    }

    protected function isOverdue(StepExecution $step, DateTimeImmutable $now): bool
    {
        return $step->isRunning()
            && $step->timeoutAt !== null
            && $step->timeoutAt <= $now;
    }

    /**
     * The caller's filter narrowed to one status, preserving every other
     * criterion so each row counts the same scope.
     */
    protected function scopedTo(ExecutionFilter $filter, ExecutionStatus $status): ExecutionFilter
    {
        return new ExecutionFilter(
            workflow: $filter->workflows,
            status: $status,
            correlationId: $filter->correlationId,
            tenantId: $filter->tenantId,
            createdAfter: $filter->createdAfter,
            createdBefore: $filter->createdBefore,
            finishedAfter: $filter->finishedAfter,
            finishedBefore: $filter->finishedBefore,
        );
    }
}
