<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\IdempotencyStatus;
use Laraflow\Workflow\IdempotencyStore;
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
        {--attempts : Also show the per-attempt history of every step}';

    protected $description = 'Inspect a workflow execution and its steps';

    public function handle(ExecutionRepository $repository, IdempotencyStore $idempotency): int
    {
        try {
            $id = $this->executionIdArgument();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $execution = $repository->find($id);
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $steps = $repository->steps($execution);

        $this->displayExecution($execution, $steps);

        if ($execution->parentExecutionId !== null) {
            $this->components->twoColumnDetail(
                '<fg=gray>Parent</>',
                "execution {$execution->parentExecutionId} (this is a sub-workflow)",
            );
            $this->newLine();
        }

        if ($execution->isWaiting()) {
            $this->displayWait($execution, $steps);
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

        if ((bool) $this->option('attempts')) {
            $this->displayAttemptHistory($repository, $execution, $steps);
            $this->displayIdempotency($idempotency, $execution, $steps);
        }

        return self::SUCCESS;
    }

    /**
     * What a waiting execution is parked on: the signal and its
     * deadline, or the resume instant — "indefinitely" said out loud
     * when nothing but a signal can wake it.
     *
     * @param  \Laraflow\Workflow\StepExecution[]  $steps
     */
    protected function displayWait(WorkflowExecution $execution, array $steps): void
    {
        foreach ($steps as $step) {
            if (! $step->isWaiting()) {
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
     * @param  \Laraflow\Workflow\StepExecution[]  $steps
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
     * @param  \Laraflow\Workflow\StepExecution[]  $steps
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
