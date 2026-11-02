<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Laraflow\Support\Formats;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use Throwable;

/**
 * @internal Rendering helpers shared by the Laraflow console commands.
 *
 * @mixin \Illuminate\Console\Command
 */
trait DisplaysExecutions
{
    /**
     * @param  StepExecution[]  $steps
     */
    protected function displayExecution(WorkflowExecution $execution, array $steps): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Execution</>', (string) $execution->id);
        $this->components->twoColumnDetail('<fg=gray>Workflow</>', $execution->workflow);

        if ($execution->planVersion > 1) {
            // Only worth a line once a workflow has versioned its plan;
            // every execution of an unversioned workflow is version 1.
            $this->components->twoColumnDetail('<fg=gray>Plan version</>', (string) $execution->planVersion);
        }
        $this->components->twoColumnDetail('<fg=gray>Status</>', $this->coloredStatus($execution->status->value));
        $this->components->twoColumnDetail('<fg=gray>Started</>', $this->formatDate($execution->startedAt));

        // finished_at covers completion, failure, and cancellation
        // alike, so this no longer has to guess which timestamp to read.
        $this->components->twoColumnDetail('<fg=gray>Finished</>', $this->formatDate($execution->finishedAt));

        if (($durationMs = $execution->durationMs()) !== null) {
            // End to end, including any time spent failed or waiting
            // out a backoff — see docs/inspection-and-observability.md.
            $this->components->twoColumnDetail('<fg=gray>Duration</>', Formats::duration($durationMs));
        }

        if ($execution->correlationId !== null) {
            $this->components->twoColumnDetail('<fg=gray>Correlation</>', $execution->correlationId);
        }

        if ($execution->tenantId !== null) {
            $this->components->twoColumnDetail('<fg=gray>Tenant</>', $execution->tenantId);
        }

        $this->displayQueueTiming($execution);

        if ($execution->isRetrying() && $execution->nextRetryAt !== null) {
            $this->components->twoColumnDetail(
                '<fg=gray>Next retry</>',
                '<fg=cyan>'.$this->formatDate($execution->nextRetryAt).'</>'
            );
        }

        if ($execution->isCancelled()) {
            $this->components->twoColumnDetail(
                '<fg=gray>Cancelled</>',
                $this->formatDate($execution->cancelledAt)
                    .($execution->cancelReason !== null ? " — {$execution->cancelReason}" : '')
            );
        } elseif ($execution->cancelRequestedAt !== null && $execution->isRunning()) {
            $this->components->twoColumnDetail(
                '<fg=gray>Cancellation requested</>',
                $this->formatDate($execution->cancelRequestedAt)
                    .' (the worker stops at its next step boundary)'
            );
        }

        if ($execution->error !== null) {
            $this->components->twoColumnDetail('<fg=gray>Error</>', "<fg=red>{$execution->error}</>");
        }

        $this->displaySteps($steps);

        if ($execution->input !== []) {
            $this->components->twoColumnDetail('<fg=gray>Input</>', $this->formatValue($execution->input, 100));
        }

        if ($execution->context !== []) {
            $this->components->twoColumnDetail('<fg=gray>Context</>', $this->formatValue($execution->context, 100));
        }

        $this->newLine();
    }

    /**
     * Say what a finished run amounts to, and give the command that
     * carries it forward.
     *
     * Three of the four outcomes are neither failures nor completions:
     * the step's retry policy, a wait, or an operator owns what happens
     * next, so the command succeeds and says who. Shared by every
     * command that runs an execution in this process, so two of them
     * cannot disagree about what a parked execution looks like — which
     * is exactly what happened while laraflow:execute kept its own copy
     * and reported a wait as "completed" (Phase C audit).
     */
    protected function reportOutcome(WorkflowExecution $execution): int
    {
        if ($execution->isRetrying()) {
            $this->components->warn(sprintf(
                'A step failed and its retry policy scheduled another attempt for %s. '
                .'Deliver it with: laraflow:recover --sync — or skip the wait with: laraflow:execute %s --now',
                $this->formatDate($execution->nextRetryAt),
                $execution->id,
            ));

            return Command::SUCCESS;
        }

        if ($execution->isWaiting()) {
            $this->components->warn(sprintf(
                'A step parked the execution on a wait. See what it is waiting for with: laraflow:show %s '
                .'— a timed wait is delivered by laraflow:recover, a signal by WorkflowRunner::signal().',
                $execution->id,
            ));

            return Command::SUCCESS;
        }

        if ($execution->isCancelled()) {
            $this->components->info('The execution was cancelled during the run; completed steps are kept.');

            return Command::SUCCESS;
        }

        if (! $execution->isCompleted()) {
            // Every path that reaches here today has already thrown for
            // a terminal failure — FailurePolicy::Continue rethrows the
            // first failure once the remaining steps have run — so this
            // is the guard that keeps the sentence below true rather
            // than a branch with a known caller. A state this method
            // has not been taught is reported as itself, not as done.
            $this->components->error(sprintf(
                'The execution finished in status [%s]. See it with: laraflow:show %s',
                $execution->status->value,
                $execution->id,
            ));

            return Command::FAILURE;
        }

        $this->components->info("Execution completed. Inspect it anytime with: laraflow:show {$execution->id}");

        return Command::SUCCESS;
    }

    /**
     * A step threw and the failure is persisted: the record as it now
     * stands, the error, and the command that picks it up again.
     */
    protected function reportFailedRun(
        ExecutionRepository $repository,
        int $executionId,
        Throwable $exception,
        string $prefix = 'A step failed: ',
    ): void {
        $failed = $repository->find($executionId);

        $this->displayExecution($failed, $repository->steps($failed));

        $this->components->error($prefix.$exception->getMessage());
        $this->components->info(
            "The failure is persisted. Recover it with: laraflow:execute {$executionId}"
        );
    }

    /**
     * When the execution was handed to the queue, when it became
     * runnable (shown only when that differs — a delayed retry), and how
     * long it waited for a worker. Nothing is shown for an execution
     * that was never dispatched: an in-process run has no queue wait,
     * and an empty row would suggest otherwise.
     */
    protected function displayQueueTiming(WorkflowExecution $execution): void
    {
        if ($execution->dispatchedAt === null) {
            return;
        }

        $this->components->twoColumnDetail('<fg=gray>Dispatched</>', $this->formatDate($execution->dispatchedAt));

        if ($execution->availableAt !== null && $execution->availableAt != $execution->dispatchedAt) {
            $this->components->twoColumnDetail(
                '<fg=gray>Available</>',
                $this->formatDate($execution->availableAt).' (held back by its retry backoff)',
            );
        }

        $now = Date::now()->toImmutable();

        if ($execution->isPending() && $execution->queueLagSeconds($now) !== null) {
            // Still waiting: the wait is open-ended, and an operator
            // seeing it grow is seeing the queue fall behind.
            $this->components->twoColumnDetail(
                '<fg=gray>Queue wait</>',
                '<fg=yellow>'.$this->formatSeconds($execution->queueLagSeconds($now)).' so far — no worker has claimed it</>',
            );
        } elseif ($execution->claimedAt !== null && $execution->queueLagSeconds($execution->claimedAt) !== null) {
            // Claimed and still owned: the wait is known exactly.
            $this->components->twoColumnDetail(
                '<fg=gray>Queue wait</>',
                $this->formatSeconds($execution->queueLagSeconds($execution->claimedAt)),
            );
        }
    }

    protected function formatSeconds(?int $seconds): string
    {
        return Formats::seconds($seconds);
    }

    /**
     * @param  StepExecution[]  $steps
     */
    protected function displaySteps(array $steps): void
    {
        $now = Date::now()->toImmutable();

        $this->table(
            ['#', 'Step', 'Status', 'Attempts', 'Next retry', 'Output', 'Error'],
            array_map(fn (StepExecution $step): array => [
                $step->position,
                class_basename($step->step),
                $this->coloredStatus($step->status->value)
                    // A running step past its deadline is hung, not dead:
                    // its claim may still be live, so nothing acts on it,
                    // but an operator deserves to see it.
                    .($step->isRunning() && $step->timeoutAt !== null && $step->timeoutAt <= $now
                        ? ' <fg=red>(overdue)</>'
                        : ''),
                $step->attempts,
                $step->nextRetryAt !== null ? $this->formatDate($step->nextRetryAt) : '',
                $this->formatValue($step->output, 60),
                $step->error ?? '',
            ], $steps),
        );
    }

    protected function coloredStatus(string $status): string
    {
        return match ($status) {
            ExecutionStatus::Completed->value, StepStatus::Completed->value => "<fg=green>{$status}</>",
            ExecutionStatus::Failed->value, StepStatus::Failed->value => "<fg=red>{$status}</>",
            ExecutionStatus::Running->value, StepStatus::Running->value => "<fg=yellow>{$status}</>",
            ExecutionStatus::Retrying->value, ExecutionStatus::Waiting->value => "<fg=cyan>{$status}</>",
            ExecutionStatus::Cancelled->value => "<fg=magenta>{$status}</>",
            StepStatus::Skipped->value => "<fg=blue>{$status}</>",
            default => "<fg=gray>{$status}</>",
        };
    }

    /**
     * Whether Laraflow's queue connection (or the application default)
     * is the sync driver, which runs a job inside the dispatch call.
     */
    protected function queueDriverIsSync(): bool
    {
        $connection = config('laraflow.queue.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : (string) config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    // The three formatters below are the console's names for the shared
    // definitions in Formats, which the dashboard renders through too.

    protected function formatDate(?DateTimeInterface $date): string
    {
        return Formats::date($date);
    }

    protected function formatValue(mixed $value, int $limit): string
    {
        return Formats::value($value, $limit);
    }
}
