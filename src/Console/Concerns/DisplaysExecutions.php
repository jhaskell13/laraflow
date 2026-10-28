<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;

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
        $this->components->twoColumnDetail('<fg=gray>Status</>', $this->coloredStatus($execution->status->value));
        $this->components->twoColumnDetail('<fg=gray>Started</>', $this->formatDate($execution->startedAt));

        // finished_at covers completion, failure, and cancellation
        // alike, so this no longer has to guess which timestamp to read.
        $this->components->twoColumnDetail('<fg=gray>Finished</>', $this->formatDate($execution->finishedAt));

        if (($durationMs = $execution->durationMs()) !== null) {
            // End to end, including any time spent failed or waiting
            // out a backoff — see docs/inspection-and-observability.md.
            $this->components->twoColumnDetail(
                '<fg=gray>Duration</>',
                $durationMs < 1000 ? "{$durationMs} ms" : sprintf('%.1f s', $durationMs / 1000),
            );
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
        if ($seconds === null) {
            return '—';
        }

        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => sprintf('%d min %d s', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%d h %d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        };
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
            ExecutionStatus::Retrying->value => "<fg=cyan>{$status}</>",
            ExecutionStatus::Cancelled->value => "<fg=magenta>{$status}</>",
            StepStatus::Skipped->value => "<fg=blue>{$status}</>",
            default => "<fg=gray>{$status}</>",
        };
    }

    protected function formatDate(?DateTimeInterface $date): string
    {
        return $date?->format('Y-m-d H:i:s') ?? '—';
    }

    protected function formatValue(mixed $value, int $limit): string
    {
        if ($value === null) {
            return '—';
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return '(unrenderable)';
        }

        return mb_strlen($json) > $limit ? mb_substr($json, 0, $limit - 1).'…' : $json;
    }
}
