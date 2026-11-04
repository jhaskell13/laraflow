<?php

declare(strict_types=1);

namespace Laraflow\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Support\JsonReport;
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
 *
 * With --pause, --resume, or --forget the exit code is the action's own:
 * a pause that landed succeeds even while some other schedule is
 * stalled, because the operator asked "did my pause work?", not "is
 * everything healthy?" — and a script that paused a schedule and saw
 * exit 1 would retry a pause that had already happened (Milestone 10
 * audit). The listing still follows, with `needs_attention` on it.
 *
 * Under --json a non-zero listing carries `reason` beside `ok: false`:
 * `ok` keeps meaning "the exit code was zero", and `reason` says why it
 * was not when nothing was refused — there is no `error`, because
 * nothing went wrong with the command.
 */
final class SchedulesCommand extends Command
{
    use ConfirmableTrait;
    use DisplaysExecutions;
    use ParsesExecutionFilters;

    protected $signature = 'laraflow:schedules
        {--pause= : Stop a schedule naming ticks until resumed; takes the workflow class}
        {--resume= : Resume a paused schedule; the paused interval is never caught up}
        {--forget= : Delete an orphaned schedule row and its tick history; refused while a declaration stands behind it}
        {--force : --forget: skip the production confirmation}
        {--json : Print one JSON document instead of the table; --forget in production also requires --force}';

    protected $description = 'List workflow schedules, and pause, resume, or forget one';

    public function handle(ScheduleRepository $schedules, ScheduleRegistry $registry, ClockInterface $clock): int
    {
        $now = $clock->now();

        try {
            $live = $registry->classes();
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $action = $this->act($schedules, $live, $now);

        if ($action === false) {
            return self::FAILURE;
        }

        $rows = $schedules->all();
        $extra = $action === null ? [] : ['action' => $action];

        if ($rows === []) {
            return $this->respond(['schedules' => [], 'needs_attention' => false] + $extra, fn () => $this->components->info(
                'No schedules exist yet. Declare schedule() on a workflow, list the class under '
                .'laraflow.schedules.workflows, and the first laraflow:schedule run registers it.'
            ));
        }

        $attention = false;
        $table = [];
        $records = [];

        foreach ($rows as $row) {
            $lastTick = $schedules->lastTick($row->id);
            $orphaned = ! in_array($row->workflow, $live, true);
            $stalled = ! $orphaned && ScheduleStatus::stalled($row, $now);
            $nextDue = ScheduleStatus::nextDue($row, $now);

            if ($orphaned || $stalled) {
                $attention = true;
            }

            $table[] = $this->row($row, $lastTick, $orphaned, $stalled, $nextDue);
            $records[] = JsonReport::schedule($row) + [
                'last_tick' => $lastTick === null ? null : JsonReport::tick($lastTick),
                'next_due_at' => JsonReport::date($nextDue),
                'state' => $this->state($row, $orphaned, $stalled),
            ];
        }

        // The action's exit code when one was taken; the listing's health
        // otherwise. `reason` only when the exit is non-zero for health.
        $code = $action !== null || ! $attention ? self::SUCCESS : self::FAILURE;
        $document = ['schedules' => $records, 'needs_attention' => $attention] + $extra
            + ($code === self::FAILURE ? ['reason' => 'needs_attention'] : []);

        return $this->respond($document, function () use ($table, $attention): void {
            $this->table(['Workflow', 'Cron', 'Timezone', 'Policy', 'Last tick (UTC)', 'Lag', 'Next due (UTC)', 'State'], $table);

            if ($attention) {
                $this->components->warn(
                    'A schedule is orphaned or stalled. An orphan has a row and no declaration; a stalled schedule has '
                    .'due ticks standing unclaimed — is the scheduler running laraflow:schedule every minute?'
                );
            }
        }, $code);
    }

    /**
     * Perform the one requested action, if any: null when none was
     * asked for, false when it was refused (the refusal is already
     * said), or the record of what was done for the document.
     *
     * @param  list<string>  $live
     * @return array{name: string, workflow: string, message: string}|false|null
     */
    protected function act(ScheduleRepository $schedules, array $live, DateTimeImmutable $now): array|false|null
    {
        $requested = array_filter([
            'pause' => $this->stringOption('pause'),
            'resume' => $this->stringOption('resume'),
            'forget' => $this->stringOption('forget'),
        ], fn (?string $value): bool => $value !== null);

        if (count($requested) > 1) {
            $this->refuse('Pass one of --pause, --resume, or --forget per run; they are separate decisions.');

            return false;
        }

        if ($requested === []) {
            return null;
        }

        $action = array_key_first($requested);
        $workflow = $this->target($schedules, (string) $requested[$action]);

        if ($workflow === null) {
            $this->refuse("No schedule exists for [{$requested[$action]}]. laraflow:schedules lists the classes that have one.");

            return false;
        }

        if ($action === 'forget' && ! $this->confirmForget($workflow)) {
            return false;
        }

        try {
            match ($action) {
                'pause' => $schedules->pause($workflow, $now),
                'resume' => $schedules->resume($workflow, $now),
                'forget' => $this->forget($schedules, $workflow, $live),
            };
        } catch (WorkflowException|InvalidArgumentException $exception) {
            $this->refuse($exception->getMessage());

            return false;
        }

        $message = match ($action) {
            'pause' => "[{$workflow}] is paused: it names no ticks until resumed.",
            'resume' => "[{$workflow}] is resumed from now. The paused interval is never caught up — paused means do not run, not run later.",
            'forget' => "[{$workflow}] and its tick history are forgotten.",
        };

        if (! $this->wantsJson()) {
            $this->components->info($message);
        }

        return ['name' => $action, 'workflow' => $workflow, 'message' => $message];
    }

    /**
     * Forgetting deletes tick history irreversibly, so it asks in
     * production exactly as laraflow:prune and the bulk cancel do;
     * --force is how a script says yes, and --json without it is
     * refused there rather than hung on a prompt nobody can answer.
     */
    protected function confirmForget(string $workflow): bool
    {
        if ($this->wantsJson() && ! (bool) $this->option('force') && $this->laravel->environment('production')) {
            $this->refuse('laraflow:schedules --forget under --json must pass --force in production: a machine cannot answer the confirmation.');

            return false;
        }

        return $this->confirmToProceed("Forgetting [{$workflow}] deletes its schedule row and tick history. Continue?");
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
     * @return list<string>
     */
    protected function row(ScheduledWorkflow $row, ?ScheduleTick $lastTick, bool $orphaned, bool $stalled, ?DateTimeImmutable $nextDue): array
    {
        return [
            class_basename($row->workflow),
            $row->cron,
            $row->timezone,
            $row->missedTickPolicy->value.($row->withoutOverlapping ? ' <fg=gray>(no overlap)</>' : ''),
            $this->lastTickCell($lastTick),
            $this->lagCell($lastTick),
            $nextDue === null ? '—' : $nextDue->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
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

        return $tick->tickAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i')." <fg=gray>({$outcome})</>";
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

    protected function state(ScheduledWorkflow $row, bool $orphaned, bool $stalled): string
    {
        return match (true) {
            $orphaned => 'orphaned',
            $stalled => 'stalled',
            $row->isPaused() => 'paused',
            default => 'ok',
        };
    }

    protected function stateCell(ScheduledWorkflow $row, bool $orphaned, bool $stalled): string
    {
        return match ($this->state($row, $orphaned, $stalled)) {
            'orphaned' => '<fg=red>orphaned</>',
            'stalled' => '<fg=red>stalled</>',
            'paused' => '<fg=yellow>paused</>',
            default => '<fg=green>ok</>',
        };
    }
}
