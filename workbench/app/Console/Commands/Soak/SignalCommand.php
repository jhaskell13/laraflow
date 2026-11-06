<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Laraflow\Exceptions\Workflow\InvalidSignalException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;
use Workbench\App\Soak\Env;
use Workbench\App\Soak\Ledger;
use Workbench\App\Soak\Stats\StatsSink;
use Workbench\App\Soak\Workflows\ApprovalWorkflow;
use Workbench\App\Soak\Workflows\EveryMinuteWorkflow;
use Workbench\App\Soak\Workflows\NoOverlapWorkflow;
use Workbench\App\Soak\Workflows\Steps\RequestApproval;

/**
 * The outside world, as far as a waiting execution is concerned:
 * approvals that arrive late, early (so the signal inbox keeps them),
 * or never; cancellations of work in flight; and probes that try to
 * reach a reserved signal through the public entry, which must be
 * refused every time — the verifier checks that they were.
 *
 * In drain mode, after the load has stopped, it approves everything
 * that is waiting so the run can settle.
 */
final class SignalCommand extends Command
{
    use RunsUntil;

    protected $signature = 'soak:signal
        {--duration=300 : Seconds to run for}
        {--drain : Approve every waiting execution at once rather than some of them}';

    protected $description = 'Soak: send signals, cancellations, and reserved-signal probes';

    public function handle(WorkflowRunner $runner, WorkflowDispatcher $dispatcher, DatabaseManager $databases, StatsSink $stats): int
    {
        $db = $databases->connection();
        $runDir = Env::string(Env::RUN_DIR, Env::defaultRunRoot()) ?? Env::defaultRunRoot();
        $probes = new Ledger($runDir.'/probes.jsonl');
        $drain = (bool) $this->option('drain');

        $this->runUntil(
            $this->intOption('duration', 300),
            1.0,
            function (int $tick) use ($runner, $dispatcher, $db, $stats, $probes, $drain): void {
                $this->approve($runner, $dispatcher, $db, $stats, $drain ? 100 : 35, $drain);

                if ($drain) {
                    return;
                }

                if (random_int(1, 100) <= 15) {
                    $this->approveEarly($runner, $db, $stats);
                }

                if (random_int(1, 100) <= 20) {
                    $this->probe($runner, $db, $probes, $stats, $tick % 2 === 0 ? 'laraflow.join.1' : 'LARAFLOW.Child.7');
                }

                if (random_int(1, 100) <= 4) {
                    $this->cancel($runner, $db, $stats);
                }
            },
            function (Throwable $exception) use ($stats, $db): void {
                /** @var Connection $db */
                $stats->record('signaller', 'tick_error', null, ['error' => $exception::class]);
                $db->disconnect();
            },
        );

        $stats->flush();

        return self::SUCCESS;
    }

    private function approve(WorkflowRunner $runner, WorkflowDispatcher $dispatcher, ConnectionInterface $db, StatsSink $stats, int $percent, bool $deliverAll): void
    {
        $waiting = $db->table('laraflow_step_executions as s')
            ->join('laraflow_workflow_executions as e', 'e.id', '=', 's.workflow_execution_id')
            ->where('e.workflow', ApprovalWorkflow::class)
            ->where('e.status', 'waiting')
            ->where('s.status', 'waiting')
            ->where('s.wait_signal', RequestApproval::SIGNAL)
            ->limit(200)
            ->pluck('e.id');

        foreach ($waiting as $id) {
            if (random_int(1, 100) > $percent) {
                continue;
            }

            try {
                $receipt = $runner->recordSignal((int) $id, RequestApproval::SIGNAL, ['decision' => 'approved', 'marker' => Env::PAYLOAD_MARKER.'signal']);
                $stats->record('signaller', $receipt->buffered ? 'approval_buffered' : 'approval_resumed');

                if ($deliverAll || random_int(0, 1) === 1) {
                    $dispatcher->dispatchExecution((int) $id);
                    $stats->record('signaller', 'delivered');
                }
            } catch (Throwable $exception) {
                $stats->record('signaller', 'approval_refused', null, ['error' => $exception::class]);
            }
        }
    }

    private function approveEarly(WorkflowRunner $runner, ConnectionInterface $db, StatsSink $stats): void
    {
        $id = $db->table('laraflow_workflow_executions')
            ->where('workflow', ApprovalWorkflow::class)
            ->whereIn('status', ['pending', 'running', 'retrying'])
            ->inRandomOrder()
            ->value('id');

        if ($id === null) {
            return;
        }

        try {
            $receipt = $runner->recordSignal((int) $id, RequestApproval::SIGNAL, ['decision' => 'approved', 'marker' => Env::PAYLOAD_MARKER.'early']);
            $stats->record('signaller', $receipt->buffered ? 'early_buffered' : 'early_resumed');
        } catch (Throwable $exception) {
            $stats->record('signaller', 'early_refused', null, ['error' => $exception::class]);
        }
    }

    /**
     * A reserved name through the public entry. Anything but
     * InvalidSignalException is a finding.
     */
    private function probe(WorkflowRunner $runner, ConnectionInterface $db, Ledger $probes, StatsSink $stats, string $signal): void
    {
        $id = $db->table('laraflow_workflow_executions')
            ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
            ->inRandomOrder()
            ->value('id');

        if ($id === null) {
            return;
        }

        try {
            $runner->signal((int) $id, $signal, ['marker' => Env::PAYLOAD_MARKER.'probe']);
            $probes->append(['signal' => $signal, 'execution' => (int) $id, 'refused' => false, 'error' => null]);
            $stats->record('signaller', 'probe_accepted');
        } catch (InvalidSignalException $exception) {
            $probes->append(['signal' => $signal, 'execution' => (int) $id, 'refused' => true, 'error' => $exception::class]);
            $stats->record('signaller', 'probe_refused');
        } catch (Throwable $exception) {
            // Refused, but not by the signal-name rule: still a refusal,
            // recorded with its class so the report can say which.
            $probes->append(['signal' => $signal, 'execution' => (int) $id, 'refused' => true, 'error' => $exception::class]);
            $stats->record('signaller', 'probe_refused_otherwise', null, ['error' => $exception::class]);
        }
    }

    private function cancel(WorkflowRunner $runner, ConnectionInterface $db, StatsSink $stats): void
    {
        $id = $db->table('laraflow_workflow_executions')
            ->whereIn('status', ['pending', 'running', 'waiting', 'retrying'])
            ->whereNotIn('workflow', [EveryMinuteWorkflow::class, NoOverlapWorkflow::class])
            ->whereNull('parent_execution_id')
            ->inRandomOrder()
            ->value('id');

        if ($id === null) {
            return;
        }

        try {
            $runner->cancel((int) $id, 'soak: cancelled in flight');
            $stats->record('signaller', 'cancelled');
        } catch (Throwable $exception) {
            $stats->record('signaller', 'cancel_refused', null, ['error' => $exception::class]);
        }
    }
}
