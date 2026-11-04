<?php

declare(strict_types=1);

namespace Laraflow\Console;

use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Support\JsonReport;
use Laraflow\Workflow\Scheduling\ScheduleAction;
use Laraflow\Workflow\Scheduling\Scheduler;
use Laraflow\Workflow\Scheduling\SchedulerRun;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * One scheduler tick: synchronise every registered declaration, claim
 * each due tick exactly once across any number of concurrent runs, and
 * start one execution per tick. Designed to run from the scheduler:
 *
 *   Schedule::command('laraflow:schedule')->everyMinute();
 *
 * Two runs racing is the designed case, not a hazard — the tick table's
 * unique index decides who starts each tick, and the loser is told,
 * quietly, that it is taken. Exit code 1 signals that human attention
 * is needed (a declaration is unusable, a start failed, or a schedule
 * row has no declaration behind it), including under --dry-run.
 */
class ScheduleCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:schedule
        {--dry-run : Name the ticks that would be claimed without claiming, starting, or writing anything}
        {--sync : Run started executions in this process instead of dispatching them to the queue}
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Run one scheduler tick: claim due schedule ticks and start their executions';

    public function handle(): int
    {
        try {
            // Resolved here rather than injected: the scheduler's options
            // are validated as they are read, and a malformed setting must
            // be this command's one-document refusal, not a container
            // exception past the --json contract.
            $run = $this->laravel->make(Scheduler::class)->run(
                dryRun: (bool) $this->option('dry-run'),
                sync: (bool) $this->option('sync'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $document = [
            'enabled' => $run->enabled,
            'dry_run' => $run->dryRun,
            'actions' => array_map(JsonReport::scheduleAction(...), $run->actions),
            'redispatched' => $run->redispatched,
            'orphans' => $run->orphans,
            'needs_attention' => $run->needsAttention(),
        ];

        if (! $run->enabled) {
            // Deliberate state, successful exit: the kill switch doing
            // its job must not page anyone.
            return $this->respond($document, fn () => $this->components->info(
                'Schedules are disabled (laraflow.schedules.enabled); nothing was considered.'
            ));
        }

        if ($run->actions === [] && $run->orphans === [] && $run->redispatched === []) {
            return $this->respond($document, fn () => $this->components->info(
                'No schedules are registered. Declare schedule() on a workflow and list the class under laraflow.schedules.workflows.'
            ));
        }

        if ($this->wantsJson()) {
            $this->emitJson($document, ! $run->needsAttention());

            return $run->needsAttention() ? self::FAILURE : self::SUCCESS;
        }

        if ($run->actions !== []) {
            $this->table(
                ['Workflow', 'Tick (UTC)', 'Outcome', 'Missed', 'Execution', 'Delivery'],
                array_map(fn (ScheduleAction $action): array => $this->row($action), $run->actions),
            );
        }

        foreach ($run->actions as $action) {
            if ($action->failed()) {
                $this->components->error(class_basename($action->workflow).': '.$action->detail);
            }
        }

        if ($run->redispatched !== []) {
            $this->components->info(sprintf(
                'Re-dispatched %d execution(s) whose queue push was lost: %s.',
                count($run->redispatched),
                implode(', ', $run->redispatched),
            ));
        }

        foreach ($run->orphans as $workflow) {
            $this->components->warn(
                "[{$workflow}] has a schedule row and no declaration behind it — unlisted, or renamed. "
                .'It names no ticks. Inspect with laraflow:schedules; forget it with --forget once its history is not wanted.'
            );
        }

        $this->summarize($run);

        return $run->needsAttention() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function row(ScheduleAction $action): array
    {
        return [
            class_basename($action->workflow),
            $action->tickAt === null ? '—' : $action->tickAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
            $this->coloredOutcome($action),
            $action->missed > 0 ? (string) $action->missed : '—',
            $action->executionId === null ? '—' : (string) $action->executionId,
            $action->delivery ?? '—',
        ];
    }

    protected function coloredOutcome(ScheduleAction $action): string
    {
        return match ($action->outcome) {
            ScheduleAction::STARTED => '<fg=green>started</>',
            ScheduleAction::CAUGHT_UP => '<fg=green>caught up</>',
            ScheduleAction::WOULD_START => '<fg=cyan>would start</>',
            ScheduleAction::SKIPPED_OVERLAP => '<fg=yellow>skipped (overlap: execution '.$action->overlappedExecutionId.' is unfinished)</>',
            ScheduleAction::TAKEN => '<fg=gray>taken (another scheduler)</>',
            ScheduleAction::PAUSED => '<fg=yellow>paused</>',
            ScheduleAction::NOT_DUE => '<fg=gray>not due</>',
            ScheduleAction::FAILED => '<fg=red>failed</>',
            default => $action->outcome,
        };
    }

    protected function summarize(SchedulerRun $run): void
    {
        $failed = count(array_filter($run->actions, fn (ScheduleAction $action): bool => $action->failed()));

        $this->components->info(sprintf(
            '%s%d tick(s) claimed, %d re-dispatched, %d orphaned, %d failed.',
            $run->dryRun ? 'Dry run — nothing was claimed or written. Would have: ' : '',
            $run->dryRun
                ? count(array_filter($run->actions, fn (ScheduleAction $action): bool => $action->outcome === ScheduleAction::WOULD_START))
                : $run->claims(),
            count($run->redispatched),
            count($run->orphans),
            $failed,
        ));
    }
}
