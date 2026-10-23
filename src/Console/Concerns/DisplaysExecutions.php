<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use DateTimeInterface;
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
        $this->components->twoColumnDetail(
            '<fg=gray>Finished</>',
            $this->formatDate($execution->completedAt ?? $execution->failedAt)
        );

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
        $this->table(
            ['#', 'Step', 'Status', 'Attempts', 'Finished', 'Output', 'Error'],
            array_map(fn (StepExecution $step): array => [
                $step->position,
                class_basename($step->step),
                $this->coloredStatus($step->status->value),
                $step->attempts,
                $this->formatDate($step->completedAt ?? $step->failedAt),
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
