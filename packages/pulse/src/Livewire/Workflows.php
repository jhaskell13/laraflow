<?php

declare(strict_types=1);

namespace Laraflow\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laraflow\Pulse\PulseSink;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * The Laraflow card for the Pulse dashboard:
 *
 * ```blade
 * <livewire:laraflow.workflows cols="6" />
 * ```
 *
 * Per workflow, for the selected period: executions finished by
 * outcome, and the slowest step; and, as of the last heartbeat, what is
 * unfinished, due, and deferred. Reads only what PulseSink recorded.
 */
#[Lazy]
final class Workflows extends Card
{
    public function render(): Renderable
    {
        [$data, $time, $runAt] = $this->remember(fn (): array => [
            'finished' => $this->finished(),
            'slowest' => $this->slowest(),
            'now' => $this->now(),
        ]);

        // By file: the package's view namespace is registered at boot,
        // and a path is what it resolves to anyway.
        return View::file(dirname(__DIR__, 2).'/resources/views/workflows.blade.php', $data + ['time' => $time, 'runAt' => $runAt]);
    }

    /**
     * @return Collection<string, array{completed: int, failed: int, cancelled: int}>
     */
    private function finished(): Collection
    {
        $rows = [];

        foreach ($this->aggregate('laraflow_executions_finished_total', 'sum', limit: 500) as $row) {
            $row = (array) $row;
            $labels = PulseSink::labels((string) ($row['key'] ?? '{}'));
            $workflow = $labels['workflow'] ?? '';
            $sum = (int) ($row['sum'] ?? 0);
            $counts = $rows[$workflow] ?? ['completed' => 0, 'failed' => 0, 'cancelled' => 0];

            match ($labels['outcome'] ?? '') {
                'completed' => $counts['completed'] += $sum,
                'failed' => $counts['failed'] += $sum,
                'cancelled' => $counts['cancelled'] += $sum,
                default => null,
            };

            $rows[$workflow] = $counts;
        }

        ksort($rows);

        return collect($rows);
    }

    /**
     * Durations in whole milliseconds, as PulseSink records them.
     *
     * @return Collection<int, array{workflow: string, step: string, max: int, avg: int, count: int}>
     */
    private function slowest(): Collection
    {
        return $this->aggregate('laraflow_step_duration_seconds', ['max', 'avg', 'count'], 'max', limit: 10)
            ->map(function (object $row): array {
                $row = (array) $row;
                $labels = PulseSink::labels((string) ($row['key'] ?? '{}'));

                return [
                    'workflow' => $labels['workflow'] ?? '',
                    'step' => $labels['step'] ?? '',
                    'max' => (int) ($row['max'] ?? 0),
                    'avg' => (int) ($row['avg'] ?? 0),
                    'count' => (int) ($row['count'] ?? 0),
                ];
            })
            ->values();
    }

    /**
     * @return array<string, float>
     */
    private function now(): array
    {
        $now = [];

        foreach (['laraflow_retries_due', 'laraflow_waits_due', 'laraflow_steps_overdue', 'laraflow_executions_deferred', 'laraflow_schedules_stalled'] as $type) {
            $now[$type] = (float) ($this->values($type)->first()->value ?? 0);
        }

        $now['unfinished'] = (float) $this->values('laraflow_executions')
            ->filter(fn (object $row): bool => PulseSink::labels((string) $row->key)['status'] !== 'failed')
            ->sum(fn (object $row): float => (float) $row->value);

        return $now;
    }
}
