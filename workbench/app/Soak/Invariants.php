<?php

declare(strict_types=1);

namespace Workbench\App\Soak;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Laraflow\Workflow\Workflow;
use stdClass;
use Throwable;
use Workbench\App\Soak\Workflows\EveryMinuteWorkflow;
use Workbench\App\Soak\Workflows\NoOverlapWorkflow;
use Workbench\App\Soak\Workflows\Steps\Charge;
use Workbench\App\Soak\Workflows\Steps\Spread;

/**
 * The nine invariants docs/soak.md promises, checked against the
 * database a run just hammered and the logs its processes wrote.
 *
 * Reads the tables directly rather than through the repository: the
 * verifier's job is to catch the repository being wrong, and a check
 * that trusts the thing it checks is not a check. Every query is plain
 * query-builder SQL that runs on all five engines.
 */
final class Invariants
{
    private const TERMINAL = ['completed', 'failed', 'cancelled'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Container $container,
        private readonly string $runDir,
        private readonly ?string $token,
        private readonly string $retentionTarget,
    ) {}

    /**
     * @return list<Finding>
     */
    public function check(): array
    {
        return [
            $this->everythingSettled(),
            $this->attemptsAreContiguous(),
            $this->attemptCountersMatchRows(),
            $this->fanOutsAreConsistent(),
            $this->oneExecutionPerTick(),
            $this->tickRowsAreCoherent(),
            $this->idempotencyIsCoherent(),
            $this->noOrphans(),
            $this->plansMatchDefinitions(),
            $this->finishedAtMatchesStatus(),
            $this->noSentinelInLogs(),
            $this->noSentinelInApplicationLogs(),
            $this->reservedSignalsWereRefused(),
        ];
    }

    /**
     * 1. Nothing rests in a non-terminal state once the run has settled.
     */
    private function everythingSettled(): Finding
    {
        $rows = $this->db->table('laraflow_workflow_executions')
            ->select('status', $this->db->raw('count(*) as n'))
            ->whereNotIn('status', self::TERMINAL)
            ->groupBy('status')
            ->get();

        $details = [];
        $total = 0;

        foreach ($rows as $row) {
            $status = (string) $row->status;
            $total += (int) $row->n;
            $ids = $this->db->table('laraflow_workflow_executions')->where('status', $status)->orderBy('id')->limit(8)->pluck('id')->all();
            $details[] = sprintf('%s: %d (e.g. %s)', $status, (int) $row->n, implode(', ', array_map('strval', $ids)));
        }

        return new Finding('settled', 'No execution rests in a non-terminal state after settling', $total, $details);
    }

    /**
     * 2a. Attempt rows for one step are numbered 1..n with no gap and no
     * duplicate, so a duplicate delivery never double-counted.
     */
    private function attemptsAreContiguous(): Finding
    {
        $rows = $this->attemptAggregates();
        $details = [];

        foreach ($rows as $row) {
            if ((int) $row->rows !== (int) $row->max_attempt) {
                $details[] = sprintf('step %d (execution %d): %d rows, highest attempt %d', (int) $row->id, (int) $row->workflow_execution_id, (int) $row->rows, (int) $row->max_attempt);
            }
        }

        return new Finding('attempts_contiguous', 'Attempt rows per step are numbered 1..n without gaps or duplicates', count($details), $details);
    }

    /**
     * 2b. The step row's attempt counter agrees with its history.
     */
    private function attemptCountersMatchRows(): Finding
    {
        $rows = $this->attemptAggregates();
        $details = [];

        foreach ($rows as $row) {
            // A parked or running step has an attempt under way and no
            // row for it yet: a row is written when an attempt *ends*.
            // On a settled run these are the steps of cancelled
            // executions; on one that stalled they are whatever was in
            // flight. Either way the counter is ahead of the history by
            // design, so the comparison does not apply to them.
            if (in_array((string) $row->status, ['waiting', 'running'], true)) {
                continue;
            }

            if ((int) $row->attempts !== (int) $row->rows) {
                $details[] = sprintf('step %d (execution %d, %s): attempts column %d, %d attempt rows', (int) $row->id, (int) $row->workflow_execution_id, (string) $row->status, (int) $row->attempts, (int) $row->rows);
            }
        }

        return new Finding('attempt_counter', 'A step row\'s attempt counter equals its attempt rows', count($details), $details);
    }

    /**
     * Per step, outside the workflow retention was allowed to prune
     * attempts from: the counter, the row count, the highest number.
     *
     * @return Collection<int, stdClass>
     */
    private function attemptAggregates(): Collection
    {
        return $this->db->table('laraflow_step_executions as s')
            ->join('laraflow_workflow_executions as e', 'e.id', '=', 's.workflow_execution_id')
            ->leftJoin('laraflow_step_attempts as a', 'a.step_execution_id', '=', 's.id')
            ->where('e.workflow', '<>', $this->retentionTarget)
            ->groupBy('s.id', 's.workflow_execution_id', 's.attempts', 's.status', 'e.status')
            ->select('s.id', 's.workflow_execution_id', 's.attempts', 's.status', 'e.status as execution_status', $this->db->raw('count(a.id) as rows'), $this->db->raw('coalesce(max(a.attempt), 0) as max_attempt'))
            ->get();
    }

    /**
     * 3. A fan-out's recorded outcome matches its members' states.
     */
    private function fanOutsAreConsistent(): Finding
    {
        $steps = $this->db->table('laraflow_step_executions as s')
            ->join('laraflow_workflow_executions as e', 'e.id', '=', 's.workflow_execution_id')
            ->where('s.step', Spread::class)
            ->whereIn('s.status', ['completed', 'failed'])
            ->whereIn('e.status', self::TERMINAL)
            ->where('e.status', '<>', 'cancelled')
            ->select('s.id', 's.status', 's.input', 's.output', 's.workflow_execution_id')
            ->get();

        $details = [];

        foreach ($steps as $step) {
            $declared = self::decode($step->input)['members'] ?? null;
            $declared = is_array($declared) ? count($declared) : null;
            $output = self::decode($step->output);

            $members = $this->db->table('laraflow_workflow_executions')
                ->where('parent_step_execution_id', (int) $step->id)
                ->select('status', $this->db->raw('count(*) as n'))
                ->groupBy('status')
                ->pluck('n', 'status')
                ->map(fn ($n): int => (int) $n)
                ->all();

            $total = array_sum($members);
            $completed = $members['completed'] ?? 0;
            $unfinished = $total - array_sum(array_intersect_key($members, array_flip(self::TERMINAL)));
            $where = sprintf('fan-out step %d of execution %d', (int) $step->id, (int) $step->workflow_execution_id);

            if ($declared !== null && $total > $declared) {
                $details[] = "{$where}: {$declared} members declared, {$total} exist";
            }

            if ($unfinished > 0) {
                $details[] = "{$where}: step is {$step->status} but {$unfinished} member(s) are unfinished";
            }

            if ((string) $step->status === 'completed') {
                if ($declared !== null && ($total !== $declared || $completed !== $declared)) {
                    $details[] = "{$where}: completed with {$completed}/{$total} members completed of {$declared} declared";
                }

                if (($output['total'] ?? null) !== $total || ($output['completed'] ?? null) !== $completed) {
                    $details[] = sprintf('%s: output says %s/%s, members say %d/%d', $where, json_encode($output['completed'] ?? null), json_encode($output['total'] ?? null), $completed, $total);
                }
            }

            if ((string) $step->status === 'failed' && $total > 0 && $completed === $total && $declared === $total) {
                $details[] = "{$where}: failed although every member completed";
            }
        }

        return new Finding('fan_out', 'A fan-out that completed has every member completed; one that failed does not', count($details), $details);
    }

    /**
     * 4a. Exactly one execution per (schedule, instant), read from the
     * executions themselves — independent of the ticks table that is
     * supposed to guarantee it.
     */
    private function oneExecutionPerTick(): Finding
    {
        $rows = $this->db->table('laraflow_workflow_executions')
            ->whereIn('workflow', [EveryMinuteWorkflow::class, NoOverlapWorkflow::class])
            ->select('id', 'workflow', 'input')
            ->orderBy('id')
            ->get();

        $seen = [];
        $details = [];

        foreach ($rows as $row) {
            $tick = self::decode($row->input)['tick'] ?? null;

            if (! is_string($tick)) {
                $details[] = sprintf('execution %d carries no tick', (int) $row->id);

                continue;
            }

            $key = class_basename((string) $row->workflow).'@'.$tick;

            if (isset($seen[$key])) {
                $details[] = sprintf('%s started twice: executions %d and %d', $key, $seen[$key], (int) $row->id);

                continue;
            }

            $seen[$key] = (int) $row->id;
        }

        return new Finding('one_per_tick', 'Exactly one execution exists per schedule and tick instant', count($details), $details);
    }

    /**
     * 4b. The ticks table says the same thing the executions do.
     */
    private function tickRowsAreCoherent(): Finding
    {
        $details = [];

        $duplicates = $this->db->table('laraflow_schedule_ticks')
            ->select('schedule_id', 'tick_at', $this->db->raw('count(*) as n'))
            ->groupBy('schedule_id', 'tick_at')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            $details[] = sprintf('schedule %d has %d rows for tick %s', (int) $row->schedule_id, (int) $row->n, (string) $row->tick_at);
        }

        $shared = $this->db->table('laraflow_schedule_ticks')
            ->whereNotNull('workflow_execution_id')
            ->select('workflow_execution_id', $this->db->raw('count(*) as n'))
            ->groupBy('workflow_execution_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($shared as $row) {
            $details[] = sprintf('execution %d is claimed by %d ticks', (int) $row->workflow_execution_id, (int) $row->n);
        }

        $startedWithout = $this->db->table('laraflow_schedule_ticks')
            ->whereIn('outcome', ['started', 'caught_up'])
            ->whereNull('workflow_execution_id')
            ->count();

        if ($startedWithout > 0) {
            $details[] = "{$startedWithout} tick(s) recorded as started without an execution";
        }

        $suppressedWithout = $this->db->table('laraflow_schedule_ticks')
            ->where('outcome', 'skipped_overlap')
            ->whereNull('overlapped_execution_id')
            ->count();

        if ($suppressedWithout > 0) {
            $details[] = "{$suppressedWithout} tick(s) suppressed for overlap without naming what they overlapped";
        }

        return new Finding('ticks', 'Tick rows are unique per instant and each names what it did', count($details), $details);
    }

    /**
     * 5. No remembered key settled twice, none left pending on a
     * completed execution, and a settled charge equals the output of
     * the step that made it.
     */
    private function idempotencyIsCoherent(): Finding
    {
        $details = [];

        $pendingOnCompleted = $this->db->table('laraflow_idempotency_keys as k')
            ->join('laraflow_workflow_executions as e', 'e.id', '=', 'k.workflow_execution_id')
            ->where('k.status', 'pending')
            ->where('e.status', 'completed')
            ->select('k.workflow_execution_id', 'k.step_position', 'k.key')
            ->get();

        foreach ($pendingOnCompleted as $row) {
            $details[] = sprintf('execution %d completed with key [%s] at position %d still pending', (int) $row->workflow_execution_id, (string) $row->key, (int) $row->step_position);
        }

        $charges = $this->db->table('laraflow_idempotency_keys as k')
            ->join('laraflow_step_executions as s', function ($join): void {
                $join->on('s.workflow_execution_id', '=', 'k.workflow_execution_id')
                    ->on('s.position', '=', 'k.step_position');
            })
            ->where('k.key', 'charge')
            ->where('k.status', 'settled')
            ->where('s.step', Charge::class)
            ->where('s.status', 'completed')
            ->whereNull('s.compensates_step_execution_id')
            ->select('k.workflow_execution_id', 'k.value', 's.output')
            ->get();

        foreach ($charges as $row) {
            if (self::decode($row->value) !== self::decode($row->output)) {
                $details[] = sprintf('execution %d: remembered charge %s but the step recorded %s', (int) $row->workflow_execution_id, json_encode(self::decode($row->value)), json_encode(self::decode($row->output)));
            }
        }

        return new Finding('idempotency', 'Remembered work is settled once and agrees with the step that did it', count($details), $details);
    }

    /**
     * 6. Every child row has its parent; every execution has its plan.
     */
    private function noOrphans(): Finding
    {
        $details = [];

        $orphans = [
            'step rows without an execution' => $this->db->table('laraflow_step_executions as s')
                ->leftJoin('laraflow_workflow_executions as e', 'e.id', '=', 's.workflow_execution_id')
                ->whereNull('e.id')->count(),
            'attempt rows without a step' => $this->db->table('laraflow_step_attempts as a')
                ->leftJoin('laraflow_step_executions as s', 's.id', '=', 'a.step_execution_id')
                ->whereNull('s.id')->count(),
            'attempt rows without an execution' => $this->db->table('laraflow_step_attempts as a')
                ->leftJoin('laraflow_workflow_executions as e', 'e.id', '=', 'a.workflow_execution_id')
                ->whereNull('e.id')->count(),
            'idempotency rows without an execution' => $this->db->table('laraflow_idempotency_keys as k')
                ->leftJoin('laraflow_workflow_executions as e', 'e.id', '=', 'k.workflow_execution_id')
                ->whereNull('e.id')->count(),
            'inbox rows without an execution' => $this->db->table('laraflow_signal_inbox as i')
                ->leftJoin('laraflow_workflow_executions as e', 'e.id', '=', 'i.workflow_execution_id')
                ->whereNull('e.id')->count(),
            'child executions whose parent is gone' => $this->db->table('laraflow_workflow_executions as c')
                ->join('laraflow_workflow_executions as p', 'p.id', '=', 'c.parent_execution_id', 'left')
                ->whereNotNull('c.parent_execution_id')
                ->whereNull('p.id')->count(),
            'executions with no step rows' => $this->db->table('laraflow_workflow_executions as e')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('laraflow_step_executions as s')->whereColumn('s.workflow_execution_id', 'e.id');
                })->count(),
        ];

        foreach ($orphans as $what => $count) {
            if ($count > 0) {
                $details[] = "{$count} {$what}";
            }
        }

        return new Finding('orphans', 'No row survives the row it belongs to', (int) array_sum($orphans), $details);
    }

    /**
     * 7. Every execution's plan rows are the steps its workflow declares.
     */
    private function plansMatchDefinitions(): Finding
    {
        $details = [];
        $definitions = [];
        $checked = 0;

        $this->db->table('laraflow_workflow_executions')
            ->select('id', 'workflow')
            ->orderBy('id')
            ->chunk(500, function (Collection $executions) use (&$details, &$definitions, &$checked): void {
                $ids = $executions->pluck('id')->map(fn ($id): int => (int) $id)->all();

                $plans = [];

                $steps = $this->db->table('laraflow_step_executions')
                    ->whereIn('workflow_execution_id', $ids)
                    ->whereNull('compensates_step_execution_id')
                    ->orderBy('workflow_execution_id')
                    ->orderBy('position')
                    ->select('workflow_execution_id', 'step')
                    ->get();

                foreach ($steps as $step) {
                    $plans[(int) $step->workflow_execution_id][] = (string) $step->step;
                }

                foreach ($executions as $execution) {
                    $workflow = (string) $execution->workflow;
                    $checked++;

                    if (! isset($definitions[$workflow])) {
                        try {
                            $instance = $this->container->make($workflow);
                            $definitions[$workflow] = $instance instanceof Workflow ? $instance->steps() : null;
                        } catch (Throwable) {
                            $definitions[$workflow] = null;
                        }
                    }

                    if ($definitions[$workflow] === null) {
                        $details[] = sprintf('execution %d: workflow %s cannot be built', (int) $execution->id, $workflow);

                        continue;
                    }

                    if (($plans[(int) $execution->id] ?? []) !== $definitions[$workflow]) {
                        $details[] = sprintf('execution %d (%s): plan %s', (int) $execution->id, class_basename($workflow), json_encode(array_map('class_basename', $plans[(int) $execution->id] ?? [])));
                    }
                }
            });

        return new Finding('plans', "Every execution's plan rows are its workflow's declared steps ({$checked} checked)", count($details), $details);
    }

    /**
     * 8. finished_at is set exactly on the terminal statuses.
     */
    private function finishedAtMatchesStatus(): Finding
    {
        $details = [];

        $terminalWithout = $this->db->table('laraflow_workflow_executions')->whereIn('status', self::TERMINAL)->whereNull('finished_at')->count();
        $liveWith = $this->db->table('laraflow_workflow_executions')->whereNotIn('status', self::TERMINAL)->whereNotNull('finished_at')->count();

        if ($terminalWithout > 0) {
            $details[] = "{$terminalWithout} terminal execution(s) without finished_at";
        }

        if ($liveWith > 0) {
            $details[] = "{$liveWith} unfinished execution(s) with finished_at set";
        }

        return new Finding('finished_at', 'finished_at is set on every terminal execution and no other', $terminalWithout + $liveWith, $details);
    }

    /**
     * 9. No credential, input, output, or context value in any log line
     * Laraflow wrote: the lifecycle log, its own warnings and errors.
     * The stub provider's log is excluded: it legitimately sees the
     * token on every request.
     */
    private function noSentinelInLogs(): Finding
    {
        [$hits, $details] = $this->scanLogs(fn (string $line): bool => self::writtenByLaraflow($line));

        return new Finding('log_leaks', 'No credential or payload sentinel appears in any line Laraflow logged', $hits, $details);
    }

    /**
     * The same scan over every other line — the application's exception
     * handler rendering an exception a step threw, the queue worker's
     * own output. Laraflow does not write these lines, so a sentinel in
     * one is reported rather than failed: it says what an operator's log
     * would actually contain, which is worth knowing even when the
     * contract does not cover it.
     */
    private function noSentinelInApplicationLogs(): Finding
    {
        [$hits, $details] = $this->scanLogs(fn (string $line): bool => ! self::writtenByLaraflow($line));

        return new Finding('log_leaks_application', 'Sentinels in lines the application (not Laraflow) logged', $hits, $details, informational: true);
    }

    /**
     * @param  callable(string): bool  $applies
     * @return array{int, list<string>}
     */
    private function scanLogs(callable $applies): array
    {
        $details = [];
        $hits = 0;
        $needles = [Env::PAYLOAD_MARKER];

        if ($this->token !== null && $this->token !== '') {
            $needles[] = $this->token;
        }

        foreach (glob($this->runDir.'/logs/*.log') ?: [] as $path) {
            if (str_ends_with($path, 'stub-server.log')) {
                continue;
            }

            $handle = fopen($path, 'r');

            if ($handle === false) {
                continue;
            }

            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                foreach ($needles as $needle) {
                    if (str_contains($line, $needle) && $applies($line)) {
                        $hits++;

                        if (count($details) < 25) {
                            $details[] = sprintf('%s:%d contains %s: %s', basename($path), $lineNumber, $needle === Env::PAYLOAD_MARKER ? 'a payload marker' : 'the credential', mb_substr(trim($line), 0, 160));
                        }

                        break;
                    }
                }
            }

            fclose($handle);
        }

        return [$hits, $details];
    }

    /**
     * A log line is Laraflow's when its message is: the lifecycle
     * logger's `Laraflow: Event`, and the package's own warnings and
     * errors, which all name Laraflow first. Everything else on a
     * worker's stream — Laravel's exception handler, the worker's
     * progress lines, stack frames — is the application's.
     */
    private static function writtenByLaraflow(string $line): bool
    {
        return preg_match('/\] \w+\.[A-Z]+: (Laraflow\b|A Laraflow\b)/', $line) === 1;
    }

    /**
     * The signaller's probes: every reserved-prefix signal it sent must
     * have been refused. Ties the soak to the threat model's third
     * invariant.
     */
    private function reservedSignalsWereRefused(): Finding
    {
        $accepted = 0;
        $sent = 0;
        $details = [];

        foreach ((new Ledger($this->runDir.'/probes.jsonl'))->read() as $probe) {
            $sent++;

            if (($probe['refused'] ?? false) !== true) {
                $accepted++;
                $details[] = sprintf('execution %s accepted reserved signal [%s]', json_encode($probe['execution'] ?? null), (string) ($probe['signal'] ?? '?'));
            }
        }

        return new Finding('reserved_signals', "Every reserved-prefix signal probe was refused ({$sent} sent)", $accepted, $details);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
