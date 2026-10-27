<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Clock\ClockInterface;
use Workbench\App\Console\Commands\Concerns\ReadsInput;

/**
 * A live view of the playground: status counts, the executions that
 * changed most recently, and queue depth, redrawn on an interval.
 *
 * Everything it shows comes from the public ExecutionRepository — the
 * same contract an application or a dashboard would use — except the
 * queue depth, which is Laravel's table and not Laraflow's business.
 * That constraint is the point: if this command needs something the
 * contract cannot answer, the contract is missing something.
 */
class WatchCommand extends Command
{
    use ReadsInput;

    protected $signature = 'playground:watch
        {--interval=1 : Seconds between refreshes}
        {--rows=12 : Executions to show}
        {--workflow= : Only executions created from this workflow class}
        {--tenant= : Only executions for this tenant}
        {--frames=0 : Stop after this many frames; 0 draws until interrupted}
        {--once : Draw a single frame and exit, for scripts and screenshots}';

    protected $description = 'Watch workflow executions in real time';

    public function handle(ExecutionRepository $repository, ClockInterface $clock): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $rows = max(1, (int) $this->option('rows'));

        $filter = new ExecutionFilter(
            workflow: $this->stringOption('workflow'),
            tenantId: $this->stringOption('tenant'),
        );

        if ((bool) $this->option('once')) {
            // One frame, no screen juggling: usable in a pipeline, and
            // the only way to show this command in documentation.
            $this->draw($repository, $clock, $filter, $rows, $interval, clear: false);

            return self::SUCCESS;
        }

        // A watch that leaves the terminal scrolled is a watch nobody
        // uses twice; the alternate screen buffer gives it back intact.
        $this->output->write("\033[?1049h");

        // 0 frames is the ordinary case: draw until interrupted. A
        // bound exists so the loop has a condition that is actually a
        // condition, and so a script can ask for a fixed number of
        // frames without --once's single-frame layout.
        $frames = max(0, (int) $this->option('frames'));

        try {
            for ($frame = 0; $frames === 0 || $frame < $frames; $frame++) {
                $this->draw($repository, $clock, $filter, $rows, $interval);

                sleep($interval);
            }
        } finally {
            $this->output->write("\033[?1049l");
        }

        return self::SUCCESS;
    }

    protected function draw(
        ExecutionRepository $repository,
        ClockInterface $clock,
        ExecutionFilter $filter,
        int $rows,
        int $interval,
        bool $clear = true,
    ): void {
        $now = $clock->now();

        if ($clear) {
            $this->output->write("\033[H\033[2J");
        }

        $this->line('');
        $this->line(sprintf(
            '  <options=bold>Laraflow</> <fg=gray>· playground</>%s<fg=gray>%s</>',
            str_repeat(' ', 34),
            $now->format('Y-m-d H:i:s'),
        ));
        $this->line('');

        $this->drawCounts($repository, $filter);
        $this->drawExecutions($repository, $filter, $rows, $now);
        $this->drawFooter($repository, $now, $interval);
    }

    protected function drawCounts(ExecutionRepository $repository, ExecutionFilter $filter): void
    {
        $parts = [];

        foreach (ExecutionStatus::cases() as $status) {
            $count = $repository->count($filter->withStatus($status));

            $parts[] = sprintf(
                '<fg=%s>%s</> %s',
                $count === 0 ? 'gray' : $this->colour($status),
                $status->value,
                $count === 0 ? "<fg=gray>{$count}</>" : "<options=bold>{$count}</>",
            );
        }

        $this->line('  '.implode('   ', $parts));
        $this->line('  <fg=gray>'.str_repeat('─', 76).'</>');
    }

    protected function drawExecutions(
        ExecutionRepository $repository,
        ExecutionFilter $filter,
        int $rows,
        DateTimeImmutable $now,
    ): void {
        $page = $repository->list($filter, limit: $rows);

        if ($page->isEmpty()) {
            $this->line('');
            $this->line('  <fg=gray>Nothing yet. Create some: testbench playground:simulate</>');
            $this->line('');

            return;
        }

        $progress = $repository->stepProgress(array_map(
            fn (WorkflowExecution $e): int|string => $e->id,
            $page->executions,
        ));

        $this->line(sprintf(
            '  <fg=gray>%-5s %-18s %-10s %-6s %-8s %s</>',
            'Id', 'Workflow', 'Status', 'Steps', 'Age', 'Detail',
        ));

        foreach ($page as $execution) {
            $counts = $progress[(string) $execution->id] ?? ['total' => 0, 'completed' => 0];

            $this->line(sprintf(
                '  %-5s %-18s <fg=%s>%-10s</> %-6s %-8s <fg=gray>%s</>',
                $execution->id,
                class_basename($execution->workflow),
                $this->colour($execution->status),
                $execution->status->value,
                "{$counts['completed']}/{$counts['total']}",
                $this->age($execution, $now),
                $this->detail($repository, $execution, $now),
            ));
        }

        $this->line('');
    }

    /**
     * The one line most worth having: what is this execution actually
     * doing, right now.
     */
    protected function detail(
        ExecutionRepository $repository,
        WorkflowExecution $execution,
        DateTimeImmutable $now,
    ): string {
        if ($execution->isRetrying() && $execution->nextRetryAt !== null) {
            $in = $execution->nextRetryAt->getTimestamp() - $now->getTimestamp();

            return $in > 0 ? "retry due in {$in}s" : 'retry due now';
        }

        if ($execution->isFailed()) {
            return mb_substr((string) $execution->error, 0, 42);
        }

        if ($execution->isCancelled()) {
            return 'cancelled'.($execution->cancelReason !== null ? ": {$execution->cancelReason}" : '');
        }

        if ($execution->isCompleted()) {
            $ms = $execution->durationMs();

            return $ms === null ? '' : sprintf('took %s', $this->duration($ms));
        }

        if ($execution->isPending()) {
            $waited = $execution->queueLagSeconds($now);

            return $waited === null ? 'waiting to be dispatched' : "queued {$waited}s";
        }

        // Running: name the step in flight, and flag it when overdue.
        $pending = 0;

        foreach ($repository->steps($execution) as $step) {
            if ($step->isRunning()) {
                return class_basename($step->step).($this->isOverdue($step, $now) ? ' (overdue)' : '');
            }

            $pending += $step->isCompleted() ? 0 : 1;
        }

        // Claimed but no step is running: either it has not reached the
        // first one yet, or every step is done and the worker is about
        // to release the claim.
        return $pending === 0 ? 'finishing' : 'starting';
    }

    protected function drawFooter(ExecutionRepository $repository, DateTimeImmutable $now, int $interval): void
    {
        $due = count($repository->dueForRetry($now, 100));
        $queue = $this->queueDepth();

        $this->line('  <fg=gray>'.str_repeat('─', 76).'</>');
        $this->line(sprintf(
            '  <fg=gray>queue: %s waiting · %s reserved%s</>',
            $queue['waiting'],
            $queue['reserved'],
            $due > 0 ? "  ·  <fg=cyan>{$due} retry(s) due — run: testbench laraflow:recover</>" : '',
        ));
        if (! (bool) $this->option('once')) {
            $this->line(sprintf(
                '  <fg=gray>refreshing every %ds · Ctrl-C to stop</>',
                $interval,
            ));
        }
        $this->line('');
    }

    /**
     * @return array{waiting: int|string, reserved: int|string}
     */
    protected function queueDepth(): array
    {
        if (! Schema::hasTable('jobs')) {
            return ['waiting' => '—', 'reserved' => '—'];
        }

        return [
            'waiting' => DB::table('jobs')->whereNull('reserved_at')->count(),
            'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
        ];
    }

    protected function age(WorkflowExecution $execution, DateTimeImmutable $now): string
    {
        $since = $execution->finishedAt ?? $execution->startedAt ?? $execution->createdAt;

        if ($since === null) {
            return '—';
        }

        return $this->elapsed($now->getTimestamp() - $since->getTimestamp());
    }

    protected function elapsed(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => intdiv($seconds, 60).'m',
            default => intdiv($seconds, 3600).'h',
        };
    }

    protected function duration(int $ms): string
    {
        return $ms < 1000 ? "{$ms}ms" : sprintf('%.1fs', $ms / 1000);
    }

    protected function isOverdue(StepExecution $step, DateTimeImmutable $now): bool
    {
        return $step->timeoutAt !== null && $step->timeoutAt <= $now;
    }

    protected function colour(ExecutionStatus $status): string
    {
        return match ($status) {
            ExecutionStatus::Completed => 'green',
            ExecutionStatus::Failed => 'red',
            ExecutionStatus::Running => 'yellow',
            ExecutionStatus::Retrying => 'cyan',
            ExecutionStatus::Cancelled => 'magenta',
            ExecutionStatus::Pending => 'blue',
        };
    }
}
