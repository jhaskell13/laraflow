<?php

declare(strict_types=1);

namespace Laraflow\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Workflow\RecoveryCandidate;
use Laraflow\Workflow\RecoveryService;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Finds interrupted executions (stale running claims and, optionally, lost
 * pending dispatches) and re-dispatches them. Designed to run from the
 * scheduler:
 *
 *   Schedule::command('laraflow:recover')->everyFiveMinutes()->withoutOverlapping();
 *
 * Every action is bounded: at most --limit executions per run, and an
 * execution whose steps have reached --max-attempts is reported as
 * abandoned instead of retried, so a crash-looping workflow cannot be
 * re-dispatched forever. Exit code 1 signals that human attention is
 * needed (work was abandoned or a recovery action failed), including under
 * --dry-run.
 */
class RecoverCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:recover
        {--stale-after= : Seconds after which a running claim counts as stale (default: laraflow.execution.stale_after)}
        {--pending-after= : Also recover pending executions older than this many seconds (default: laraflow.recovery.pending_after; scan disabled when unset)}
        {--limit= : Maximum executions handled in one run (default: laraflow.recovery.limit)}
        {--max-attempts= : Report executions whose steps reached this many attempts as abandoned (default: laraflow.recovery.max_attempts)}
        {--workflow= : Only consider executions created from this workflow class}
        {--dry-run : Report what would happen without dispatching, running, or changing anything}
        {--sync : Run recovered executions in this process instead of dispatching them to the queue}';

    protected $description = 'Find interrupted workflow executions and retry them within a bounded policy';

    public function handle(RecoveryService $service, Config $config): int
    {
        try {
            $staleAfter = $this->positiveIntOption(
                'stale-after',
                (int) $config->get('laraflow.execution.stale_after', 300),
            );
            $limit = $this->positiveIntOption(
                'limit',
                (int) $config->get('laraflow.recovery.limit', 100),
            );
            $maxAttempts = $this->positiveIntOption(
                'max-attempts',
                (int) $config->get('laraflow.recovery.max_attempts', 3),
            );
            $pendingAfter = $this->pendingAfterOption($config);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $workflow = $this->option('workflow');
        $workflow = is_string($workflow) && $workflow !== '' ? $workflow : null;

        $candidates = $service->scan(
            staleAfterSeconds: $staleAfter,
            pendingAfterSeconds: $pendingAfter,
            limit: $limit,
            maxAttempts: $maxAttempts,
            workflow: $workflow,
        );

        if ($candidates === []) {
            $this->components->info('Nothing to recover.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        $counts = ['dispatched' => 0, 'ran' => 0, 'abandoned' => 0, 'unrecoverable' => 0, 'skipped' => 0, 'failed' => 0];
        $rows = [];

        foreach ($candidates as $candidate) {
            $rows[] = [
                $candidate->execution->id,
                class_basename($candidate->execution->workflow),
                $this->coloredStatus($candidate->execution->status->value),
                str_replace('_', ' ', $candidate->reason),
                $candidate->attempts,
                $this->formatDate($this->interruptedSince($candidate)),
                $this->act($service, $candidate, $dryRun, $sync, $counts),
            ];
        }

        $this->table(
            ['Id', 'Workflow', 'Status', 'Reason', 'Attempts', 'Since', 'Action'],
            $rows,
        );

        $this->summarize($counts, count($candidates), $dryRun);

        return ($counts['abandoned'] > 0 || $counts['unrecoverable'] > 0 || $counts['failed'] > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Perform (or, under --dry-run, name) the recovery action for one
     * candidate and return the Action cell for the report table. A failing
     * candidate never aborts the run: recovery is per execution, and the
     * rest of the batch still deserves its retry.
     *
     * @param  array<string, int>  $counts
     */
    protected function act(
        RecoveryService $service,
        RecoveryCandidate $candidate,
        bool $dryRun,
        bool $sync,
        array &$counts,
    ): string {
        if ($candidate->workflowMissing) {
            // Such a retry fails before any step runs, so attempts never
            // grow and the attempt bound alone could not stop the loop.
            $counts['unrecoverable']++;

            return '<fg=red>unrecoverable (workflow class missing)</>';
        }

        if ($candidate->abandoned) {
            $counts['abandoned']++;

            return '<fg=red>abandoned (max attempts reached)</>';
        }

        if ($dryRun) {
            return $sync ? 'would run' : 'would dispatch';
        }

        try {
            if ($sync) {
                $service->runInline($candidate);
                $counts['ran']++;

                return '<fg=green>completed</>';
            }

            $service->dispatch($candidate);
            $counts['dispatched']++;

            return '<fg=green>dispatched</>';
        } catch (ExecutionNotRunnableException) {
            // Completed between scan and action; nothing left to do.
            $counts['skipped']++;

            return 'completed meanwhile';
        } catch (ExecutionAlreadyClaimedException) {
            // Another worker picked it up between scan and action.
            $counts['skipped']++;

            return 'claimed by another worker';
        } catch (Throwable $exception) {
            $counts['failed']++;

            $message = mb_substr($exception->getMessage(), 0, 80);

            return "<fg=red>failed: {$message}</>";
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected function summarize(array $counts, int $total, bool $dryRun): void
    {
        if ($dryRun) {
            $this->components->info(sprintf(
                'Dry run over %d execution(s): %d recoverable, %d abandoned, %d unrecoverable. Nothing was changed.',
                $total,
                $total - $counts['abandoned'] - $counts['unrecoverable'],
                $counts['abandoned'],
                $counts['unrecoverable'],
            ));
        } else {
            $this->components->info(sprintf(
                'Handled %d execution(s): %d dispatched, %d ran, %d abandoned, %d unrecoverable, %d skipped, %d failed.',
                $total,
                $counts['dispatched'],
                $counts['ran'],
                $counts['abandoned'],
                $counts['unrecoverable'],
                $counts['skipped'],
                $counts['failed'],
            ));
        }

        if ($counts['abandoned'] > 0) {
            $this->components->warn(
                'Abandoned executions have exceeded the recovery attempt bound. '
                .'Investigate them, then re-run deliberately with: laraflow:execute <id>'
            );
        }

        if ($counts['unrecoverable'] > 0) {
            $this->components->warn(
                'Unrecoverable executions reference a workflow class this code base no longer '
                .'has. Restore or redeploy the class, or handle the execution manually.'
            );
        }
    }

    protected function interruptedSince(RecoveryCandidate $candidate): ?DateTimeImmutable
    {
        return $candidate->reason === WorkflowExecutionRetryScheduled::REASON_STALE_CLAIM
            ? $candidate->execution->claimedAt
            : $candidate->execution->createdAt;
    }

    /**
     * An omitted option falls back to its configured default; a supplied
     * value must be a positive integer.
     */
    protected function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return $value === null ? $default : $this->validatePositiveInt($name, $value);
    }

    /**
     * Null (option omitted and config unset) disables the pending scan
     * entirely. Zero is rejected deliberately — `--pending-after=0` would
     * sweep up every freshly created execution.
     */
    protected function pendingAfterOption(Config $config): ?int
    {
        $value = $this->option('pending-after');

        if ($value !== null) {
            return $this->validatePositiveInt('pending-after', $value);
        }

        $configured = $config->get('laraflow.recovery.pending_after');

        return $configured === null ? null : (int) $configured;
    }

    protected function validatePositiveInt(string $name, mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            $display = is_scalar($value) ? (string) $value : get_debug_type($value);

            throw new InvalidArgumentException(
                "The --{$name} option must be a positive integer, got [{$display}]."
            );
        }

        return (int) $value;
    }
}
