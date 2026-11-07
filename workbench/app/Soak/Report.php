<?php

declare(strict_types=1);

namespace Workbench\App\Soak;

use Illuminate\Database\ConnectionInterface;

/**
 * What a run amounts to, in one document: the configuration it ran
 * under, the faults it injected, what the tables hold now, what every
 * process measured, the invariants' verdicts, and the benchmark
 * numbers beside the committed baseline — advisory, never a gate
 * (docs/decisions/0013).
 */
final class Report
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $runDir,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<Finding>  $findings
     * @param  array<string, mixed>  $bench
     * @param  list<array<string, mixed>>  $faults
     * @return array<string, mixed>
     */
    public function build(array $meta, array $findings, array $bench, array $faults): array
    {
        $stats = $this->aggregateStats();
        $ledger = $this->aggregateLedger();
        $tables = $this->tables();

        $deleted = (int) ($stats['prune']['totals']['deleted'] ?? 0);
        $byStatus = $tables['executions_by_status'];
        $terminal = is_array($byStatus) ? (int) array_sum(array_intersect_key($byStatus, array_flip(['completed', 'failed', 'cancelled']))) : 0;
        $loadMinutes = max(1 / 60, ((float) ($meta['load_seconds'] ?? 60)) / 60);

        $claimOutcomes = is_array($stats['claim']['outcomes'] ?? null) ? $stats['claim']['outcomes'] : [];

        $benchmarks = $bench + [
            'claims_deferred_by_tenant_ceiling' => (int) ($claimOutcomes['throttled'] ?? 0),
            'executions_created' => $ledger['created'],
            'executions_finished' => $terminal + $deleted,
            'throughput_per_minute' => round(($terminal + $deleted) / $loadMinutes, 1),
            'claim_contention_ratio' => $this->ratio($stats['claim']['outcomes'] ?? [], ['already_claimed', 'claim_lost']),
            'claim_p50_ms' => $stats['claim']['p50'] ?? null,
            'claim_p95_ms' => $stats['claim']['p95'] ?? null,
            'checkpoint_p50_ms' => $stats['checkpoint']['p50'] ?? null,
            'checkpoint_p95_ms' => $stats['checkpoint']['p95'] ?? null,
            'save_step_p95_ms' => $stats['save_step']['p95'] ?? null,
            'prune_ms_per_1k_rows' => $this->pruneCost($stats['prune'] ?? []),
        ];

        $passed = array_values(array_filter($findings, fn (Finding $f): bool => ! $f->passed())) === [];

        return [
            'run' => basename($this->runDir),
            'meta' => $meta,
            'passed' => $passed,
            'faults' => $faults,
            'tables' => $tables,
            'ledger' => $ledger,
            'log_errors' => $this->logErrors(),
            'stats' => $stats,
            'benchmarks' => $benchmarks,
            'invariants' => array_map(fn (Finding $f): array => $f->toArray(), $findings),
        ];
    }

    /**
     * Compare the benchmarks to the committed baseline for this engine.
     * Advisory: a list of sentences, never an exit code.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public static function compareToBaseline(array $report, string $baselinePath): array
    {
        if (! is_file($baselinePath)) {
            return ['No baseline file at '.$baselinePath.'.'];
        }

        $baselines = json_decode((string) file_get_contents($baselinePath), true);
        $engine = is_array($report['meta'] ?? null) ? (string) ($report['meta']['driver'] ?? '') : '';
        $baseline = is_array($baselines) && isset($baselines[$engine]) && is_array($baselines[$engine]) ? $baselines[$engine] : null;

        if ($baseline === null) {
            return ["No baseline recorded for {$engine}; this run's numbers are a candidate."];
        }

        $advice = [];
        $benchmarks = is_array($report['benchmarks'] ?? null) ? $report['benchmarks'] : [];

        foreach (['throughput_per_minute' => 'higher', 'checkpoint_p95_ms' => 'lower', 'claim_p95_ms' => 'lower', 'snapshot_ms' => 'lower', 'prune_ms_per_1k_rows' => 'lower'] as $key => $better) {
            $now = $benchmarks[$key] ?? null;
            $was = $baseline[$key] ?? null;

            if (! is_numeric($now) || ! is_numeric($was) || (float) $was == 0.0) {
                continue;
            }

            $change = ((float) $now - (float) $was) / (float) $was;
            $worse = $better === 'higher' ? $change < -0.25 : $change > 0.25;

            if ($worse) {
                $advice[] = sprintf('%s moved from %s to %s (%+.0f%%) — worth a look, not a failure.', $key, $was, $now, $change * 100);
            }
        }

        return $advice === [] ? ['Within 25% of the baseline on every tracked number.'] : $advice;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $advice
     */
    public static function markdown(array $report, array $advice): string
    {
        $meta = is_array($report['meta'] ?? null) ? $report['meta'] : [];
        $bench = is_array($report['benchmarks'] ?? null) ? $report['benchmarks'] : [];
        $tables = is_array($report['tables'] ?? null) ? $report['tables'] : [];
        $invariants = is_array($report['invariants'] ?? null) ? $report['invariants'] : [];
        $faults = is_array($report['faults'] ?? null) ? $report['faults'] : [];
        $stats = is_array($report['stats'] ?? null) ? $report['stats'] : [];

        $lines = [];
        $lines[] = sprintf('## Soak %s — %s', (string) ($report['run'] ?? ''), ($report['passed'] ?? false) === true ? '✅ every invariant held' : '❌ an invariant was violated');
        $lines[] = '';
        $lines[] = sprintf('Engine **%s**, queue **%s**, %ss of load at %s/s, %s workers, %s schedulers; settled: %s.', (string) ($meta['driver'] ?? '?'), (string) ($meta['queue'] ?? '?'), (string) ($meta['load_seconds'] ?? '?'), (string) ($meta['rate'] ?? '?'), (string) ($meta['workers'] ?? '?'), (string) ($meta['schedulers'] ?? '?'), ($meta['settled'] ?? false) === true ? 'yes' : '**no**');
        $lines[] = '';
        $lines[] = '### Invariants';
        $lines[] = '';
        $lines[] = '| Invariant | Result | Violations | First detail |';
        $lines[] = '|---|---|---|---|';

        foreach ($invariants as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $details = is_array($finding['details'] ?? null) ? $finding['details'] : [];
            $lines[] = sprintf('| %s | %s | %s | %s |', (string) ($finding['description'] ?? ''), ($finding['passed'] ?? false) === true ? 'pass' : '**FAIL**', (string) ($finding['violations'] ?? 0), str_replace('|', '\\|', (string) ($details[0] ?? '')));
        }

        $lines[] = '';
        $lines[] = '### Faults injected';
        $lines[] = '';
        $counts = [];

        foreach ($faults as $fault) {
            if (is_array($fault)) {
                $kind = (string) ($fault['kind'] ?? '?');
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            }
        }

        $lines[] = $counts === [] ? 'None.' : implode(', ', array_map(fn (string $k, int $n): string => "{$n}× {$k}", array_keys($counts), $counts));
        $lines[] = '';
        $lines[] = '### Numbers';
        $lines[] = '';
        $lines[] = '| Measure | Value |';
        $lines[] = '|---|---|';

        foreach ($bench as $key => $value) {
            $lines[] = sprintf('| %s | %s |', $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        $lines[] = '';
        $lines[] = '### Against the baseline';
        $lines[] = '';

        foreach ($advice as $sentence) {
            $lines[] = '- '.$sentence;
        }

        $lines[] = '';
        $lines[] = '### What the tables hold';
        $lines[] = '';

        foreach ($tables as $table => $counts) {
            $lines[] = sprintf('- **%s**: %s', $table, is_array($counts) ? json_encode($counts) : (string) $counts);
        }

        $claim = is_array($stats['claim'] ?? null) ? $stats['claim'] : [];
        $signaller = is_array($stats['signaller'] ?? null) ? $stats['signaller'] : [];
        $recover = is_array($stats['recover'] ?? null) ? $stats['recover'] : [];
        $lines[] = sprintf('- **claims**: %s', json_encode($claim['outcomes'] ?? []));
        $lines[] = sprintf('- **signaller**: %s', json_encode($signaller['outcomes'] ?? []));
        $lines[] = sprintf('- **recovery totals**: %s', json_encode($recover['totals'] ?? []));
        $lines[] = '';
        $lines[] = '### Error lines in the process logs, by shape';
        $lines[] = '';
        $errors = is_array($report['log_errors'] ?? null) ? $report['log_errors'] : [];

        if ($errors === []) {
            $lines[] = 'None.';
        }

        foreach ($errors as $shape => $n) {
            $lines[] = sprintf('- %s× `%s`', is_scalar($n) ? (string) $n : '?', str_replace('`', "'", (string) $shape));
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Every process's timing records, folded: per operation the
     * outcomes and the percentiles of the successful calls; for the
     * loops, their totals.
     *
     * @return array<string, array<string, mixed>>
     */
    private function aggregateStats(): array
    {
        $byOp = [];

        foreach (Ledger::readAll($this->runDir.'/stats/*.jsonl') as $record) {
            $op = (string) ($record['op'] ?? 'unknown');
            $outcome = (string) ($record['outcome'] ?? 'unknown');

            $byOp[$op] ??= ['count' => 0, 'outcomes' => [], 'ms' => [], 'totals' => []];
            $byOp[$op]['count']++;
            $byOp[$op]['outcomes'][$outcome] = ($byOp[$op]['outcomes'][$outcome] ?? 0) + 1;

            if ($outcome === 'ok' && is_numeric($record['ms'] ?? null)) {
                $byOp[$op]['ms'][] = (float) $record['ms'];
            }

            foreach (['counts', 'report'] as $bag) {
                if (is_array($record[$bag] ?? null)) {
                    foreach ($record[$bag] as $key => $value) {
                        if (is_numeric($value)) {
                            $byOp[$op]['totals'][(string) $key] = ($byOp[$op]['totals'][(string) $key] ?? 0) + (int) $value;
                        }
                    }
                }
            }

            if ($op === 'schedule' && is_numeric($record['ticks'] ?? null)) {
                $byOp[$op]['totals']['ticks'] = ($byOp[$op]['totals']['ticks'] ?? 0) + (int) $record['ticks'];
            }
        }

        foreach ($byOp as $op => $data) {
            $ms = $data['ms'];
            sort($ms);
            $byOp[$op]['p50'] = self::percentile($ms, 0.50);
            $byOp[$op]['p95'] = self::percentile($ms, 0.95);
            $byOp[$op]['max'] = $ms === [] ? null : round((float) end($ms), 2);
            $byOp[$op]['total_ms'] = round(array_sum($ms), 1);
            unset($byOp[$op]['ms']);
            ksort($byOp[$op]['outcomes']);
        }

        ksort($byOp);

        return $byOp;
    }

    /**
     * @return array{created: int, by_scenario: array<string, int>, by_mode: array<string, int>}
     */
    private function aggregateLedger(): array
    {
        $created = 0;
        $byScenario = [];
        $byMode = [];

        foreach ((new Ledger($this->runDir.'/ledger.jsonl'))->read() as $record) {
            if (($record['kind'] ?? null) !== 'created' || ! isset($record['id'])) {
                continue;
            }

            $created++;
            $scenario = (string) ($record['scenario'] ?? '?');
            $mode = (string) ($record['mode'] ?? '?');
            $byScenario[$scenario] = ($byScenario[$scenario] ?? 0) + 1;
            $byMode[$mode] = ($byMode[$mode] ?? 0) + 1;
        }

        ksort($byScenario);
        ksort($byMode);

        return ['created' => $created, 'by_scenario' => $byScenario, 'by_mode' => $byMode];
    }

    /**
     * @return array<string, array<string, int>|int>
     */
    private function tables(): array
    {
        $group = function (string $table, string $column): array {
            $rows = $this->db->table($table)->select($column, $this->db->raw('count(*) as n'))->groupBy($column)->orderBy($column)->get();
            $counts = [];

            foreach ($rows as $row) {
                $counts[(string) $row->{$column}] = (int) $row->n;
            }

            return $counts;
        };

        $byWorkflow = [];

        foreach ($this->db->table('laraflow_workflow_executions')->select('workflow', $this->db->raw('count(*) as n'))->groupBy('workflow')->get() as $row) {
            $byWorkflow[class_basename((string) $row->workflow)] = (int) $row->n;
        }

        ksort($byWorkflow);

        return [
            'executions_by_status' => $group('laraflow_workflow_executions', 'status'),
            'executions_by_workflow' => $byWorkflow,
            'steps_by_status' => $group('laraflow_step_executions', 'status'),
            'attempts_by_status' => $group('laraflow_step_attempts', 'status'),
            'idempotency_by_status' => $group('laraflow_idempotency_keys', 'status'),
            'ticks_by_outcome' => $group('laraflow_schedule_ticks', 'outcome'),
            'signal_inbox_rows' => $this->db->table('laraflow_signal_inbox')->count(),
            'compensation_rows' => $this->db->table('laraflow_step_executions')->whereNotNull('compensates_step_execution_id')->count(),
        ];
    }

    /**
     * The error lines the processes logged, by shape, so the report says
     * what kind of failures the run produced — a lock error on SQLite,
     * a lost connection during an outage — without anyone reading a
     * megabyte of worker output.
     *
     * @return array<string, int>
     */
    private function logErrors(): array
    {
        $counts = [];

        foreach (glob($this->runDir.'/logs/*.log') ?: [] as $path) {
            if (str_ends_with($path, 'stub-server.log')) {
                continue;
            }

            $handle = fopen($path, 'r');

            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                if (preg_match('/\] \w+\.ERROR: (.{0,90})/', $line, $m) !== 1) {
                    continue;
                }

                $shape = (string) preg_replace('/\d{2,}/', 'N', $m[1]);
                $counts[$shape] = ($counts[$shape] ?? 0) + 1;
            }

            fclose($handle);
        }

        arsort($counts);

        return array_slice($counts, 0, 12, true);
    }

    /**
     * @param  array<string, int>  $outcomes
     * @param  list<string>  $numerator
     */
    private function ratio(array $outcomes, array $numerator): ?float
    {
        $total = array_sum($outcomes);

        if ($total === 0) {
            return null;
        }

        return round(array_sum(array_intersect_key($outcomes, array_flip($numerator))) / $total, 4);
    }

    /**
     * @param  array<string, mixed>  $prune
     */
    private function pruneCost(array $prune): ?float
    {
        $totals = is_array($prune['totals'] ?? null) ? $prune['totals'] : [];
        $rows = (int) ($totals['scrubbed'] ?? 0) + (int) ($totals['attempt_rows'] ?? 0) + (int) ($totals['deleted'] ?? 0);
        $ms = (float) ($prune['total_ms'] ?? 0);

        return $rows === 0 ? null : round($ms / $rows * 1000, 1);
    }

    /**
     * @param  list<float>  $sorted
     */
    private static function percentile(array $sorted, float $p): ?float
    {
        if ($sorted === []) {
            return null;
        }

        $index = (int) floor($p * (count($sorted) - 1));

        return round($sorted[$index], 2);
    }
}
