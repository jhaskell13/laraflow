<?php

declare(strict_types=1);

namespace Laraflow\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Support\WorkflowName;
use Laraflow\Workflow\Scheduling\ScheduledWorkflow;
use Laraflow\Workflow\Scheduling\ScheduleRegistry;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\Scheduling\ScheduleStatus;
use Laraflow\Workflow\Scheduling\ScheduleTick;
use Laraflow\Workflow\Scheduling\TickOutcome;
use Psr\Clock\ClockInterface;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Every schedule, with its cadence, its last tick and the lag it was
 * claimed with, when it next fires, and whether it is paused, orphaned,
 * or stalled. Exits non-zero when any schedule is orphaned or stalled,
 * so the scheduled health check that runs laraflow:summary can run this
 * too.
 */
class SchedulesCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:schedules
        {--pause= : Stop a schedule naming ticks until resumed; takes the workflow class}
        {--resume= : Resume a paused schedule; the paused interval is never caught up}
        {--forget= : Delete an orphaned schedule row and its tick history; refused while a declaration stands behind it}';

    protected $description = 'List workflow schedules, and pause, resume, or forget one';

    public function handle(ScheduleRepository $schedules, ScheduleRegistry $registry, ClockInterface $clock): int
    {
        $now = $clock->now();
        $live = $registry->classes();

        $acted = $this->act($schedules, $live, $now);

        if ($acted === self::FAILURE) {
            return self::FAILURE;
        }

        $rows = $schedules->all();

        if ($rows === []) {
            $this->components->info(
                'No schedules exist yet. Declare schedule() on a workflow, list the class under '
                .'laraflow.schedules.workflows, and the first laraflow:schedule run registers it.'
            );

            return self::SUCCESS;
        }

        $attention = false;

        $this->table(
            ['Workflow', 'Cron', 'Timezone', 'Policy', 'Last tick (UTC)', 'Lag', 'Next due (UTC)', 'State'],
            array_map(function (ScheduledWorkflow $row) use ($schedules, $live, $now, &$attention): array {
                return $this->row($row, $schedules->lastTick($row->id), $live, $now, $attention);
            }, $rows),
        );

        if ($attention) {
            $this->components->warn(
                'A schedule is orphaned or stalled. An orphan has a row and no declaration; a stalled schedule has '
                .'due ticks standing unclaimed — is the scheduler running laraflow:schedule every minute?'
            );
        }

        return $attention ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Perform the one requested action, if any. SUCCESS means carry on
     * to the listing; FAILURE means the action itself was refused.
     *
     * @param  list<string>  $live
     */
    protected function act(ScheduleRepository $schedules, array $live, DateTimeImmutable $now): int
    {
        $requested = array_filter([
            'pause' => $this->stringOption('pause'),
            'resume' => $this->stringOption('resume'),
            'forget' => $this->stringOption('forget'),
        ], fn (?string $value): bool => $value !== null);

        if (count($requested) > 1) {
            $this->components->error('Pass one of --pause, --resume, or --forget per run; they are separate decisions.');

            return self::FAILURE;
        }

        if ($requested === []) {
            return self::SUCCESS;
        }

        $action = array_key_first($requested);
        $workflow = $this->target($schedules, (string) $requested[$action]);

        if ($workflow === null) {
            $this->components->error(
                "No schedule exists for [{$requested[$action]}]. laraflow:schedules lists the classes that have one."
            );

            return self::FAILURE;
        }

        try {
            match ($action) {
                'pause' => $schedules->pause($workflow, $now),
                'resume' => $schedules->resume($workflow, $now),
                'forget' => $this->forget($schedules, $workflow, $live),
            };
        } catch (WorkflowException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(match ($action) {
            'pause' => "[{$workflow}] is paused: it names no ticks until resumed.",
            'resume' => "[{$workflow}] is resumed from now. The paused interval is never caught up — paused means do not run, not run later.",
            'forget' => "[{$workflow}] and its tick history are forgotten.",
        });

        return self::SUCCESS;
    }

    /**
     * Forgetting is for history nothing stands behind: a schedule whose
     * class is still registered would be re-created by the next tick
     * run, and deleting a live schedule's history should not be a side
     * effect of a typo.
     *
     * @param  list<string>  $live
     */
    protected function forget(ScheduleRepository $schedules, string $workflow, array $live): void
    {
        if (in_array($workflow, $live, true)) {
            throw new InvalidArgumentException(
                "[{$workflow}] has a live declaration and cannot be forgotten. Unlist it from "
                .'laraflow.schedules.workflows first; --forget is for orphaned rows whose history is not wanted.'
            );
        }

        $schedules->forget($workflow);
    }

    /**
     * The row's class string as the operator typed it, or resolved from
     * a bare name. Exact match first: an orphan's class may no longer
     * exist, and it must still be addressable.
     */
    protected function target(ScheduleRepository $schedules, string $name): ?string
    {
        if ($schedules->find($name) !== null) {
            return $name;
        }

        try {
            $resolved = WorkflowName::resolve($name, $this->laravel);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $schedules->find($resolved) === null ? null : $resolved;
    }

    /**
     * @param  list<string>  $live
     * @return list<string>
     */
    protected function row(ScheduledWorkflow $row, ?ScheduleTick $lastTick, array $live, DateTimeImmutable $now, bool &$attention): array
    {
        $orphaned = ! in_array($row->workflow, $live, true);
        $stalled = ! $orphaned && ScheduleStatus::stalled($row, $now);

        if ($orphaned || $stalled) {
            $attention = true;
        }

        $nextDue = ScheduleStatus::nextDue($row, $now);

        return [
            class_basename($row->workflow),
            $row->cron,
            $row->timezone,
            $row->missedTickPolicy->value.($row->withoutOverlapping ? ' <fg=gray>(no overlap)</>' : ''),
            $this->lastTickCell($lastTick),
            $this->lagCell($lastTick),
            $nextDue === null ? '—' : $nextDue->format('Y-m-d H:i'),
            $this->stateCell($row, $orphaned, $stalled),
        ];
    }

    protected function lastTickCell(?ScheduleTick $tick): string
    {
        if ($tick === null) {
            return '<fg=gray>never</>';
        }

        $outcome = match ($tick->outcome) {
            TickOutcome::Started => 'started',
            TickOutcome::CaughtUp => 'caught up',
            TickOutcome::SkippedOverlap => 'skipped (overlap)',
        };

        return $tick->tickAt->format('Y-m-d H:i')." <fg=gray>({$outcome})</>";
    }

    /**
     * How long after its instant the last tick was claimed — the
     * scheduler's own latency, read from the one row that records both.
     */
    protected function lagCell(?ScheduleTick $tick): string
    {
        if ($tick === null || $tick->createdAt === null) {
            return '—';
        }

        return $this->formatSeconds(max(0, $tick->createdAt->getTimestamp() - $tick->tickAt->getTimestamp()));
    }

    protected function stateCell(ScheduledWorkflow $row, bool $orphaned, bool $stalled): string
    {
        return match (true) {
            $orphaned => '<fg=red>orphaned</>',
            $stalled => '<fg=red>stalled</>',
            $row->isPaused() => '<fg=yellow>paused</>',
            default => '<fg=green>ok</>',
        };
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
