<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowScheduleSkipped;
use Laraflow\Events\WorkflowScheduleTicked;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\InvalidScheduleException;
use Laraflow\Exceptions\Workflow\TenantThrottledException;
use Laraflow\Exceptions\Workflow\TickAlreadyClaimedException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\MissedTickPolicy;
use Laraflow\Workflow\Schedule;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowResolver;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal The engine behind the tick run; use the console command.
 * Not yet covered by the compatibility policy.
 *
 * One run, for every registered schedule: synchronise the declaration
 * to its row, name the due tick, claim it by inserting its row, start
 * the execution in the same transaction, and dispatch after the commit.
 * Several schedulers running at once is the designed case — the tick
 * table's unique index decides who starts each tick, and everyone else
 * is told, quietly, that it is taken. See docs/schedules.md.
 *
 * Every action is bounded by one shared budget (`schedules.limit`):
 * claims, catch-up ticks, and re-dispatched lost pushes all draw on it,
 * most urgent first, and the remainder waits for the next run.
 */
final class Scheduler
{
    /** Whether this run delivers in-process — read by the re-dispatch heal. */
    private bool $sync = false;

    public function __construct(
        private ScheduleRegistry $registry,
        private ScheduleRepository $schedules,
        private ExecutionRepository $executions,
        private WorkflowRunner $runner,
        private WorkflowResolver $resolver,
        private WorkflowDispatcher $dispatcher,
        private EventEmitter $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private SchedulerOptions $options,
    ) {}

    public function run(bool $dryRun = false, bool $sync = false): SchedulerRun
    {
        $this->sync = $sync;

        if (! $this->options->enabled) {
            // The kill switch: every schedule stops at once without
            // being unlisted, for the incident where nothing may start.
            return new SchedulerRun(enabled: false, dryRun: $dryRun, actions: [], orphans: [], redispatched: []);
        }

        // The clock's own timezone, like every other Laraflow write:
        // Eloquent stores the wall-clock of the value it is given and
        // reads columns back in the application timezone, so a value
        // normalised to UTC here would shift by the app's offset on the
        // way back (the Milestone 10 audit's worst finding). The
        // declaration's timezone decides what "02:00" means; the
        // instant is recorded the way the rest of the package records
        // instants, and rendered as UTC only at display time.
        $now = $this->clock->now();

        $budget = $this->options->limit;
        $actions = [];
        $declared = [];

        foreach ($this->registry->classes() as $class) {
            $declared[$class] = true;

            try {
                $definition = $this->registry->definitionFor($class);
            } catch (InvalidScheduleException $exception) {
                // One broken declaration must not stop the others from
                // ticking; it is a failed row and a non-zero exit.
                $actions[] = new ScheduleAction($class, ScheduleAction::FAILED, detail: $exception->getMessage());

                continue;
            }

            $row = $dryRun ? $this->schedules->find($class) : $this->schedules->sync($definition, $now);

            if ($row === null) {
                // Only under --dry-run: the real run would create the row
                // with last_tick_at = now, and nothing before that is due.
                $actions[] = new ScheduleAction(
                    $class,
                    ScheduleAction::NOT_DUE,
                    detail: 'would register; a new schedule never backfills',
                );

                continue;
            }

            if ($row->isPaused()) {
                $actions[] = new ScheduleAction($class, ScheduleAction::PAUSED);

                continue;
            }

            try {
                foreach ($this->tickSchedule($definition, $row, $now, $dryRun, $sync, $budget) as $action) {
                    $actions[] = $action;
                }
            } catch (Throwable $exception) {
                // The tick arithmetic or the row itself is unusable; one
                // broken schedule must not stop the others from ticking.
                $actions[] = new ScheduleAction($class, ScheduleAction::FAILED, detail: $exception->getMessage());
            }
        }

        $orphans = [];

        foreach ($this->schedules->all() as $row) {
            if (! isset($declared[$row->workflow])) {
                // A row no declaration stands behind: unlisted, renamed,
                // or its schedule() removed. Reported, never deleted —
                // a schedule that stopped running is exactly what the
                // health check exists to surface.
                $orphans[] = $row->workflow;
            }
        }

        $redispatched = $dryRun ? [] : $this->redispatch($now, $budget);

        return new SchedulerRun(enabled: true, dryRun: $dryRun, actions: $actions, orphans: $orphans, redispatched: $redispatched);
    }

    /**
     * Decide and claim one schedule's due ticks.
     *
     * @return list<ScheduleAction>
     */
    private function tickSchedule(
        ScheduleDefinition $definition,
        ScheduledWorkflow $row,
        DateTimeImmutable $now,
        bool $dryRun,
        bool $sync,
        int &$budget,
    ): array {
        $due = Ticks::mostRecent($definition->schedule->expression, $definition->timezone, $now);

        if ($row->lastTickAt !== null && $due->getTimestamp() <= $row->lastTickAt->getTimestamp()) {
            return [new ScheduleAction($definition->workflow, ScheduleAction::NOT_DUE, tickAt: $due)];
        }

        $claims = $this->plan($definition->schedule, $definition->timezone, $row->lastTickAt, $due, $now);

        if ($claims === []) {
            // A gap the dedupe emptied: the only instant past the mark
            // is the second occurrence of a repeated DST hour, which
            // already fired under its first.
            return [new ScheduleAction($definition->workflow, ScheduleAction::NOT_DUE, tickAt: $due)];
        }

        $actions = [];

        foreach ($claims as $claim) {
            if ($budget < 1) {
                // The shared bound. What was not claimed is still due,
                // and the next run resumes exactly here.
                break;
            }

            $budget--;

            if ($dryRun) {
                $actions[] = new ScheduleAction(
                    $definition->workflow,
                    ScheduleAction::WOULD_START,
                    tickAt: $claim->tickAt,
                    missed: $claim->missed,
                );

                continue;
            }

            $action = $this->claim($definition, $row, $claim, $now, $sync);
            $actions[] = $action;

            if ($action->failed()) {
                // The same start would fail for every later tick of this
                // run; the next run retries from the unmoved high-water
                // mark.
                break;
            }
        }

        return $actions;
    }

    /**
     * Which instants this run should claim for one schedule, oldest
     * first, each knowing how many unclaimed instants it stands for.
     *
     * @return list<TickClaim>
     */
    private function plan(
        Schedule $schedule,
        string $timezone,
        ?DateTimeImmutable $last,
        DateTimeImmutable $due,
        DateTimeImmutable $now,
    ): array {
        if ($last === null) {
            // A row that predates the rule that sync always writes the
            // mark; the current instant is the only honest claim.
            return [new TickClaim($due, TickOutcome::Started, 0, null)];
        }

        $gap = Ticks::between($schedule->expression, $timezone, $last, $due);

        if ($gap === []) {
            return [];
        }

        if ($schedule->missedTickPolicy === MissedTickPolicy::Skip) {
            // Only the most recent due instant runs; the gap is counted
            // on its row. When the walk hit its bound before reaching
            // $due, the bound is what the count honestly is.
            $reachedDue = $gap[count($gap) - 1]->getTimestamp() === $due->getTimestamp();
            $missed = $reachedDue ? count($gap) - 1 : count($gap);

            return [new TickClaim($due, TickOutcome::Started, $missed, $missed > 0 ? $gap[0] : null)];
        }

        // CatchUp: instants older than the window are skipped and
        // counted; the rest run oldest first, each told its own tick,
        // bounded per run. The current due instant is always worth
        // claiming — a tick older than the window with nothing after it
        // is still the schedule's present.
        $cutoff = $now->modify("-{$this->options->catchUpWindowSeconds} seconds");

        $skipped = [];
        $claimable = [];

        foreach ($gap as $instant) {
            if ($instant->getTimestamp() < $cutoff->getTimestamp() && $instant->getTimestamp() !== $due->getTimestamp()) {
                $skipped[] = $instant;
            } else {
                $claimable[] = $instant;
            }
        }

        if ($claimable === [] || $claimable[count($claimable) - 1]->getTimestamp() < $due->getTimestamp()) {
            // Everything enumerable fell out of the window (or the walk
            // was capped short of $due): the present instant still runs.
            $claimable[] = $due;
        }

        $claims = [];

        foreach (array_slice($claimable, 0, $this->options->maxCatchUp) as $index => $instant) {
            $claims[] = new TickClaim(
                tickAt: $instant,
                outcome: $instant->getTimestamp() === $due->getTimestamp() ? TickOutcome::Started : TickOutcome::CaughtUp,
                missed: $index === 0 ? count($skipped) : 0,
                missedFrom: $index === 0 && $skipped !== [] ? $skipped[0] : null,
            );
        }

        return $claims;
    }

    /**
     * Claim one tick: suppress it when overlap says so, otherwise start
     * its execution in the claiming transaction and hand it to delivery.
     */
    private function claim(
        ScheduleDefinition $definition,
        ScheduledWorkflow $row,
        TickClaim $claim,
        DateTimeImmutable $now,
        bool $sync,
    ): ScheduleAction {
        $workflow = $definition->workflow;

        if ($row->withoutOverlapping && ($running = $this->unfinishedPreviousExecution($row)) !== null) {
            try {
                $this->schedules->claimTick(
                    $row,
                    $claim->tickAt,
                    TickOutcome::SkippedOverlap,
                    null,
                    $now,
                    $claim->missed,
                    $claim->missedFrom,
                    $running->id,
                );
            } catch (TickAlreadyClaimedException $exception) {
                return new ScheduleAction($workflow, ScheduleAction::TAKEN, tickAt: $claim->tickAt, detail: $exception->getMessage());
            }

            $this->events->emit(new WorkflowScheduleSkipped(
                workflow: $workflow,
                correlationId: null,
                tenantId: $row->tenantId,
                tickAt: $claim->tickAt,
                overlappedExecutionId: $running->id,
            ));

            return new ScheduleAction(
                $workflow,
                ScheduleAction::SKIPPED_OVERLAP,
                tickAt: $claim->tickAt,
                missed: $claim->missed,
                overlappedExecutionId: $running->id,
            );
        }

        $options = new ExecutionOptions(
            correlationId: 'schedule:'.class_basename($workflow).'@'.$claim->tickAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i\\Z'),
            tenantId: $row->tenantId,
        );

        try {
            $tick = $this->schedules->claimTick(
                $row,
                $claim->tickAt,
                $claim->outcome,
                fn (): WorkflowExecution => $this->runner->start(
                    $definition->instance,
                    $definition->schedule->inputFor($claim->tickAt),
                    $options,
                ),
                $now,
                $claim->missed,
                $claim->missedFrom,
            );
        } catch (TickAlreadyClaimedException $exception) {
            return new ScheduleAction($workflow, ScheduleAction::TAKEN, tickAt: $claim->tickAt, detail: $exception->getMessage());
        } catch (Throwable $exception) {
            // The tick rolled back with the execution, so the next run
            // finds it still unclaimed and retries — which is right,
            // because the failure was the code's and the code may have
            // been fixed by then.
            $this->logger->warning('Laraflow could not start a scheduled execution.', [
                'workflow' => $workflow,
                'tick_at' => $claim->tickAt->format(DATE_ATOM),
                'error_class' => $exception::class,
            ]);

            return new ScheduleAction($workflow, ScheduleAction::FAILED, tickAt: $claim->tickAt, detail: $exception->getMessage());
        }

        $executionId = $tick->workflowExecutionId;
        assert($executionId !== null);

        $this->events->emit(new WorkflowScheduleTicked(
            executionId: $executionId,
            workflow: $workflow,
            correlationId: $options->correlationId,
            tenantId: $options->tenantId,
            tickAt: $claim->tickAt,
            missed: $claim->missed,
            caughtUp: $claim->outcome === TickOutcome::CaughtUp,
        ));

        [$delivery, $detail] = $this->deliver($executionId, $definition, $sync);

        return new ScheduleAction(
            $workflow,
            $claim->outcome === TickOutcome::CaughtUp ? ScheduleAction::CAUGHT_UP : ScheduleAction::STARTED,
            tickAt: $claim->tickAt,
            executionId: $executionId,
            missed: $claim->missed,
            delivery: $delivery,
            detail: $detail,
        );
    }

    /**
     * Hand a started execution to whatever runs executions: the queue
     * through the same Handoff the console and dashboard use, or this
     * process under --sync.
     *
     * @return array{0: string, 1: string|null}
     */
    private function deliver(int $executionId, ScheduleDefinition $definition, bool $sync): array
    {
        if ($sync) {
            try {
                $execution = $this->runner->execute($executionId, $definition->instance);

                return [$execution->status->value, null];
            } catch (TenantThrottledException $deferred) {
                // Not a failure: the deferral is recorded, and the next
                // run's re-dispatch scan waits out its timer.
                return ['deferred', $deferred->getMessage()];
            } catch (Throwable $exception) {
                // The failure is persisted on the execution. The tick
                // succeeded, but a run the operator asked for in this
                // process did not — the report says so and the run exits
                // non-zero, exactly as laraflow:recover --sync does.
                return ['failed', $exception->getMessage()];
            }
        }

        $handoff = Handoff::attempt($this->dispatcher, $this->executions, $this->executions->find($executionId));

        return [$handoff->outcome, $handoff->ok ? null : $handoff->message];
    }

    /**
     * The execution overlap suppression watches: the one the previous
     * fired tick started, unless it has finished — or gone, which
     * retention may have made it, and gone does not hold a schedule up.
     */
    private function unfinishedPreviousExecution(ScheduledWorkflow $row): ?WorkflowExecution
    {
        $tick = $this->schedules->lastFiredTick($row->id);

        if ($tick === null || $tick->workflowExecutionId === null) {
            return null;
        }

        try {
            $execution = $this->executions->find($tick->workflowExecutionId);
        } catch (ExecutionNotFoundException) {
            return null;
        }

        return $execution->isFinished() ? null : $execution;
    }

    /**
     * Heal lost dispatches: pending executions this scheduler's own
     * ticks started, re-pushed once per redispatch window.
     *
     * @return list<int>
     */
    private function redispatch(DateTimeImmutable $now, int $budget): array
    {
        if ($budget < 1) {
            return [];
        }

        $before = $now->modify("-{$this->options->redispatchAfterSeconds} seconds");

        $redispatched = [];

        foreach ($this->schedules->redispatchCandidates($before, $budget) as $executionId) {
            try {
                if ($this->sync) {
                    // --sync means this process runs executions: an
                    // application with no queue must not have its heal
                    // path push jobs nothing will take.
                    $execution = $this->executions->find($executionId);
                    $this->runner->execute($executionId, $this->resolver->resolve($executionId, $execution->workflow), reexecuteFailed: false);
                } else {
                    $this->dispatcher->dispatchExecution($executionId);
                }

                $redispatched[] = $executionId;
            } catch (TenantThrottledException) {
                // The deferral is recorded on the execution; its own
                // timer owns the next attempt.
            } catch (Throwable $exception) {
                // Refused (an operator ran it between the scan and now),
                // the push failed again, or an inline run's step failed —
                // the last is persisted on the execution; all three are
                // the next run's or the operator's to pick up.
                $this->logger->warning('Laraflow could not re-dispatch a scheduled execution.', [
                    'execution_id' => $executionId,
                    'error_class' => $exception::class,
                ]);
            }
        }

        return $redispatched;
    }
}
