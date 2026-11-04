<?php

declare(strict_types=1);

namespace Laraflow\Console;

use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Support\JsonReport;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\IdempotencyStatus;
use Laraflow\Workflow\IdempotencyStore;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\Scheduling\TickOutcome;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 */
class ShowCommand extends Command
{
    use DisplaysExecutions;
    use ParsesExecutionId;

    protected $signature = 'laraflow:show
        {execution : The id of the execution to inspect}
        {--attempts : Also show the per-attempt history of every step, and the remembered work}
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Inspect a workflow execution and its steps';

    public function handle(ExecutionRepository $repository, IdempotencyStore $idempotency, ScheduleRepository $schedules): int
    {
        try {
            $id = $this->executionIdArgument();
            $execution = $repository->find($id);
        } catch (InvalidArgumentException|WorkflowException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $steps = $repository->steps($execution);
        $tick = $schedules->tickForExecution($execution->id);
        $attempts = (bool) $this->option('attempts');

        $document = [
            'execution' => JsonReport::execution($execution, detailed: true) + [
                'steps' => array_map(JsonReport::step(...), $steps),
                'waiting_on' => $this->waitingOn($repository, $execution, $steps),
                'started_by' => $tick === null ? null : JsonReport::tick($tick),
            ],
        ];

        if ($attempts) {
            $document['attempts'] = array_map(JsonReport::attempt(...), $repository->attemptHistory($execution));
            $document['remembered'] = array_map(JsonReport::remembered(...), $idempotency->records($execution->id));
        }

        return $this->respond($document, function () use ($repository, $idempotency, $execution, $steps, $tick, $attempts): void {
            $this->displayExecution($execution, $steps);

            if ($execution->parentExecutionId !== null) {
                $this->components->twoColumnDetail(
                    '<fg=gray>Parent</>',
                    "execution {$execution->parentExecutionId} (this is a sub-workflow)",
                );
                $this->newLine();
            }

            if ($tick !== null) {
                $this->components->twoColumnDetail(
                    '<fg=gray>Started by</>',
                    sprintf(
                        'schedule tick %s UTC%s%s',
                        $tick->tickAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
                        $tick->outcome === TickOutcome::CaughtUp ? ' (caught up)' : '',
                        $tick->missed > 0 ? " ({$tick->missed} missed before it)" : '',
                    ),
                );
                $this->newLine();
            }

            if ($execution->isWaiting()) {
                $this->displayWait($repository, $execution, $steps);
            }

            if ($execution->claimToken !== null) {
                $this->components->twoColumnDetail(
                    '<fg=gray>Claimed at</>',
                    $this->formatDate($execution->claimedAt).' (a worker owns or owned this execution)'
                );
                $this->newLine();
            }

            if ($execution->fruitlessClaims > 1) {
                // One is the claim any run opens with. More than one, and
                // the execution has been claimed and dropped before a step
                // was written — the signature of a fault recovery's step
                // attempt bound cannot see.
                $this->components->twoColumnDetail(
                    '<fg=gray>Claims without progress</>',
                    "<fg=yellow>{$execution->fruitlessClaims}</> (claimed repeatedly without reaching a step)"
                );
                $this->newLine();
            }

            if ($attempts) {
                $this->displayAttemptHistory($repository, $execution, $steps);
                $this->displayIdempotency($idempotency, $execution, $steps);
            }
        });
    }

    /**
     * What a waiting execution is parked on, as data: one entry per
     * waiting step — the kind (`signal`, `timer`, `child`, `join`), the
     * signal name or child id where there is one, and the instant that
     * ends or re-checks the wait.
     *
     * @param  StepExecution[]  $steps
     * @return list<array<string, mixed>>
     */
    protected function waitingOn(ExecutionRepository $repository, WorkflowExecution $execution, array $steps): array
    {
        if (! $execution->isWaiting()) {
            return [];
        }

        $waits = [];

        foreach ($steps as $step) {
            if (! $step->isWaiting()) {
                continue;
            }

            if (Wait::isJoinSignal($step->waitSignal)) {
                $progress = $repository->childProgress($step->id);

                $waits[] = [
                    'step' => $step->step,
                    'kind' => 'join',
                    'members' => $progress->total(),
                    'unfinished' => $progress->unfinished(),
                    'next_check_at' => JsonReport::date($execution->nextRetryAt),
                ];

                continue;
            }

            if (Wait::isChildSignal($step->waitSignal)) {
                $waits[] = [
                    'step' => $step->step,
                    'kind' => 'child',
                    'child' => Wait::childIdFrom((string) $step->waitSignal) ?: null,
                    'next_check_at' => JsonReport::date($execution->nextRetryAt),
                ];

                continue;
            }

            $waits[] = $step->waitSignal === null
                ? ['step' => $step->step, 'kind' => 'timer', 'until' => JsonReport::date($step->nextRetryAt)]
                : ['step' => $step->step, 'kind' => 'signal', 'signal' => $step->waitSignal, 'until' => JsonReport::date($step->timeoutAt)];
        }

        return $waits;
    }

    /**
     * What a waiting execution is parked on: the signal and its
     * deadline, or the resume instant — "indefinitely" said out loud
     * when nothing but a signal can wake it.
     *
     * @param  StepExecution[]  $steps
     */
    protected function displayWait(ExecutionRepository $repository, WorkflowExecution $execution, array $steps): void
    {
        foreach ($steps as $step) {
            if (! $step->isWaiting()) {
                continue;
            }

            if (Wait::isJoinSignal($step->waitSignal)) {
                // A join is never indefinite either: the parent checks
                // on the group at every heartbeat, and the counts come
                // from the one aggregate the join itself reads.
                $progress = $repository->childProgress($step->id);

                $this->components->twoColumnDetail(
                    '<fg=gray>Waiting</>',
                    sprintf(
                        '%s on %d of %d fan-out member(s) still running (next check %s)',
                        class_basename($step->step),
                        $progress->unfinished(),
                        $progress->total(),
                        $this->formatDate($execution->nextRetryAt),
                    ),
                );
                $this->newLine();

                continue;
            }

            if (Wait::isChildSignal($step->waitSignal)) {
                // A child wait is never indefinite: the parent checks on
                // the child at every heartbeat, recorded on the execution.
                $childId = Wait::childIdFrom((string) $step->waitSignal);
                $detail = ($childId === 0 ? 'on a malformed child signal '.$step->waitSignal : 'on child execution '.$childId)
                    .' (next check '.$this->formatDate($execution->nextRetryAt).')';
            } elseif ($step->waitSignal === null) {
                $detail = 'until '.$this->formatDate($step->nextRetryAt);
            } else {
                $detail = "for signal [{$step->waitSignal}]".($step->timeoutAt !== null
                    ? ', until '.$this->formatDate($step->timeoutAt)
                    : ', indefinitely');
            }

            $this->components->twoColumnDetail(
                '<fg=gray>Waiting</>',
                class_basename($step->step).' '.$detail,
            );
            $this->newLine();
        }
    }

    /**
     * The append-only attempt history: why each attempt ended, how long
     * it took, and which failures scheduled retries — the step table
     * alone only ever shows the latest attempt.
     *
     * Read in one query via attemptHistory() rather than one per step.
     * The rows already carry their step's id, so the step class names
     * are resolved from the steps this command had already loaded.
     *
     * @param  StepExecution[]  $steps
     */
    protected function displayAttemptHistory(
        ExecutionRepository $repository,
        WorkflowExecution $execution,
        array $steps
    ): void {
        $names = [];

        foreach ($steps as $step) {
            $names[(string) $step->id] = class_basename($step->step);
        }

        $rows = [];

        foreach ($repository->attemptHistory($execution) as $attempt) {
            $rows[] = [
                $names[(string) $attempt->stepExecutionId] ?? '(removed step)',
                $attempt->attempt,
                $this->coloredStatus($attempt->status->value),
                $this->formatDate($attempt->startedAt),
                $attempt->durationMs() !== null ? $attempt->durationMs().' ms' : '—',
                $attempt->nextRetryAt !== null ? $this->formatDate($attempt->nextRetryAt) : '',
                $attempt->error ?? '',
            ];
        }

        if ($rows === []) {
            $this->components->info('No finished attempts recorded yet.');

            return;
        }

        $this->table(
            ['Step', 'Attempt', 'Outcome', 'Started', 'Duration', 'Scheduled retry', 'Error'],
            $rows,
        );
    }

    /**
     * What the execution has remembered: step, key, status, and when.
     * Values are never printed — they are the results of side effects
     * and may carry anything. A pending record is an unresolved
     * outcome, and is said so.
     *
     * @param  StepExecution[]  $steps
     */
    protected function displayIdempotency(IdempotencyStore $store, WorkflowExecution $execution, array $steps): void
    {
        $records = $store->records($execution->id);

        if ($records === []) {
            return;
        }

        $names = [];

        foreach ($steps as $step) {
            $names[$step->position] = class_basename($step->step);
        }

        $rows = [];

        foreach ($records as $record) {
            $rows[] = [
                $names[$record->stepPosition] ?? "position {$record->stepPosition}",
                $record->key,
                $record->status === IdempotencyStatus::Pending
                    ? '<fg=yellow>pending (outcome unknown)</>'
                    : $this->coloredStatus($record->status->value),
                $this->formatDate($record->startedAt),
                $record->settledAt !== null ? $this->formatDate($record->settledAt) : '',
                $record->errorClass ?? '',
            ];
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Remembered work</>', (string) count($records));
        $this->table(['Step', 'Key', 'Status', 'Began', 'Settled', 'Error'], $rows);
    }
}
