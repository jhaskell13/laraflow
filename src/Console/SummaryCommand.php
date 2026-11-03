<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Workflow\AttentionReport;
use Laraflow\Workflow\AttentionScan;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Psr\Clock\ClockInterface;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * One screen answering "is anything wrong right now?": how many
 * executions are in each status for a given scope, and the conditions
 * that warrant a look — retries and waits that are due, steps past
 * their deadline, stalled schedules, and the oldest execution still
 * unfinished.
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

    public function handle(ExecutionRepository $repository, AttentionScan $scan, ClockInterface $clock): int
    {
        try {
            $filter = $this->filterFromOptions();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $total = $repository->count($filter);
        $report = $scan->scan($filter, $clock->now());

        // An empty table is only quiet when nothing else is wrong: a
        // stalled schedule with no executions yet is exactly the state
        // a dead scheduler leaves, and the one this check must not
        // sleep through.
        if ($total === 0 && ! $report->needsAttention()) {
            $this->components->info(
                $filter->isEmpty()
                    ? 'No executions found. Try: laraflow:demo'
                    : 'No executions match that filter.'
            );

            return self::SUCCESS;
        }

        if ($total > 0) {
            $this->displayCounts($repository, $filter, $total);
        }

        return $this->displayAttention($report);
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
            $count = $repository->count($filter->withStatus($status));

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
     * The conditions worth an operator's attention, found by the same
     * scan the dashboard's overview runs. Exits non-zero when any is
     * present, so this command can be used as a health check by a
     * scheduler or a deploy gate.
     */
    protected function displayAttention(AttentionReport $report): int
    {
        if ($report->dueRetries !== []) {
            $oldest = $report->dueRetries[0];

            $this->components->warn(sprintf(
                '%d retr%s due now; the most overdue is execution [%s], due %s. Deliver them with: laraflow:recover',
                count($report->dueRetries),
                count($report->dueRetries) === 1 ? 'y is' : 'ies are',
                $oldest->id,
                $this->formatDate($oldest->nextRetryAt),
            ));
        }

        if ($report->dueWaits !== []) {
            $oldest = $report->dueWaits[0];

            $this->components->warn(sprintf(
                '%d wait%s due for delivery; the most overdue is execution [%s], due %s. Deliver them with: laraflow:recover',
                count($report->dueWaits),
                count($report->dueWaits) === 1 ? ' is' : 's are',
                $oldest->id,
                $this->formatDate($oldest->nextRetryAt),
            ));
        }

        if ($report->overdueSteps !== []) {
            $this->components->warn(sprintf(
                '%d execution(s) have a running step past its deadline: %s. '
                .'Laraflow does not interrupt running PHP; these are bounded by your queue worker timeout.',
                count($report->overdueSteps),
                implode(', ', array_map(
                    fn (array $row): string => "[{$row['execution']->id}] ".class_basename($row['step']->step),
                    array_slice($report->overdueSteps, 0, 5),
                )),
            ));
        }

        if ($report->stalledSchedules !== []) {
            $this->components->warn(sprintf(
                '%d schedule%s stalled — due ticks stand unclaimed: %s. '
                .'Is the scheduler running laraflow:schedule every minute? Inspect with: laraflow:schedules',
                count($report->stalledSchedules),
                count($report->stalledSchedules) === 1 ? ' is' : 's are',
                implode(', ', array_map(
                    fn ($row): string => class_basename($row->workflow),
                    array_slice($report->stalledSchedules, 0, 5),
                )),
            ));
        }

        if ($report->oldestUnfinished !== null) {
            $this->components->info(sprintf(
                'Oldest unfinished execution: [%s] %s, created %s.',
                $report->oldestUnfinished->id,
                class_basename($report->oldestUnfinished->workflow),
                $this->formatDate($report->oldestUnfinished->createdAt),
            ));
        }

        if (! $report->needsAttention()) {
            $this->components->info('Nothing needs attention: no retries or waits due, no step past its deadline, no schedule stalled.');
        }

        $this->newLine();

        return $report->needsAttention() ? self::FAILURE : self::SUCCESS;
    }
}
