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

        if ($execution->correlationId !== null) {
            $this->components->twoColumnDetail('<fg=gray>Correlation</>', $execution->correlationId);
        }

        if ($execution->tenantId !== null) {
            $this->components->twoColumnDetail('<fg=gray>Tenant</>', $execution->tenantId);
        }

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
