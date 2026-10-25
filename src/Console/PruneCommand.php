<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Workflow\RetentionPolicy;
use Laraflow\Workflow\RetentionReport;
use Laraflow\Workflow\RetentionService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Applies the configured retention policy to finished executions.
 * Laraflow never prunes on its own; schedule this:
 *
 *   Schedule::command('laraflow:prune --force')->daily();
 *
 * `--force` is needed there because this command deletes durable
 * records irreversibly and so uses Laravel's standard production
 * confirmation, exactly as `migrate` and `db:wipe` do. A dry run
 * changes nothing and never asks.
 *
 * Every option overrides the matching `laraflow.retention.*` value for
 * one invocation, which is how an operator prunes one workflow or one
 * tenant on a different schedule from everything else.
 */
class PruneCommand extends Command
{
    use ConfirmableTrait;
    use DisplaysExecutions;
    use ParsesExecutionFilters;

    protected $signature = 'laraflow:prune
        {--scrub-after= : Clear payloads of completed and cancelled executions finished this many days ago (default: laraflow.retention.scrub_after_days)}
        {--prune-attempts-after= : Delete attempt history for finished executions this old (default: laraflow.retention.prune_attempts_after_days)}
        {--delete-after= : Delete completed and cancelled executions this old (default: laraflow.retention.delete_after_days)}
        {--delete-failed-after= : Delete failed executions this old; separate because they can still be recovered (default: laraflow.retention.delete_failed_after_days)}
        {--limit= : Maximum executions handled per tier in one run (default: laraflow.retention.limit)}
        {--chunk= : Executions written at once (default: laraflow.retention.chunk)}
        {--workflow= : Only consider executions created from this workflow class}
        {--tenant= : Only consider executions for this tenant id}
        {--dry-run : Report what would be removed without removing anything}
        {--force : Skip the production confirmation}';

    protected $description = 'Apply the retention policy to finished workflow executions';

    public function handle(RetentionService $service, Config $config, LoggerInterface $logger): int
    {
        try {
            $policy = $this->policy($config);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $policy->isEnabled()) {
            return $this->refuse();
        }

        foreach ($policy->warnings() as $warning) {
            $this->components->warn($warning);
        }

        $dryRun = (bool) $this->option('dry-run');

        // A dry run writes nothing, so it has nothing to confirm.
        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $report = $service->prune(
                $policy,
                dryRun: $dryRun,
                workflow: $this->stringOption('workflow'),
                tenantId: $this->stringOption('tenant'),
            );
        } catch (Throwable $exception) {
            // A scheduled run surfaces through its exit code, so a
            // storage failure must not look like a quiet success.
            $this->components->error('Retention failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->report($report, $policy);
        $this->record($logger, $report);

        return self::SUCCESS;
    }

    /**
     * The configured policy with any option overrides applied.
     *
     * @throws InvalidArgumentException for a malformed option or an invalid bound.
     */
    protected function policy(Config $config): RetentionPolicy
    {
        $retention = $config->get('laraflow.retention', []);
        $retention = is_array($retention) ? $retention : [];

        foreach ([
            'scrub-after' => 'scrub_after_days',
            'prune-attempts-after' => 'prune_attempts_after_days',
            'delete-after' => 'delete_after_days',
            'delete-failed-after' => 'delete_failed_after_days',
            'limit' => 'limit',
            'chunk' => 'chunk',
        ] as $option => $key) {
            $value = $this->option($option);

            if ($value === null) {
                continue;
            }

            if (! is_numeric($value)) {
                throw new InvalidArgumentException(
                    "The --{$option} option must be a number of days, got [{$value}]."
                );
            }

            $retention[$key] = (int) $value;
        }

        // RetentionPolicy does the bounds checking, so an invalid value
        // is reported the same way whether it came from configuration
        // or from the command line.
        return RetentionPolicy::fromArray($retention);
    }

    /**
     * Nothing is configured. This is a misconfiguration rather than a
     * quiet no-op: a scheduled prune that silently does nothing is
     * indistinguishable from one that is working, right up until
     * someone looks at the size of the attempt table.
     */
    protected function refuse(): int
    {
        $this->components->error('No retention bounds are configured, so there is nothing to prune.');

        $this->components->bulletList([
            'laraflow.retention.scrub_after_days — clear payloads, keep the audit trail',
            'laraflow.retention.prune_attempts_after_days — delete attempt history',
            'laraflow.retention.delete_after_days — delete completed and cancelled executions',
            'laraflow.retention.delete_failed_after_days — delete failed executions, which can still be recovered',
        ]);

        $this->components->info(
            'Set at least one, or pass it for a single run: laraflow:prune --prune-attempts-after=30 --dry-run'
        );

        return self::FAILURE;
    }

    protected function report(RetentionReport $report, RetentionPolicy $policy): void
    {
        if ($report->isEmpty()) {
            $this->components->info(
                $report->dryRun
                    ? 'Nothing would be pruned: no finished execution is past any configured bound.'
                    : 'Nothing to prune: no finished execution is past any configured bound.'
            );

            return;
        }

        $this->table(
            ['Tier', 'Bound', 'Executions', 'Detail'],
            array_values(array_filter([
                $this->row('deleted', $policy->deleteAfterDays, $report->deleted, 'with their steps and attempts'),
                $this->row('deleted (failed)', $policy->deleteFailedAfterDays, $report->deletedFailed, 'recoverable work, discarded deliberately'),
                $this->row('attempts pruned', $policy->pruneAttemptsAfterDays, $report->attemptExecutions, $report->dryRun
                    ? 'row count unknown until the run'
                    : $report->attemptRows.' attempt row(s)'),
                $this->row('payloads scrubbed', $policy->scrubAfterDays, $report->scrubbed, 'input, context, and step outputs'),
            ])),
        );

        $this->components->info(sprintf(
            $report->dryRun
                ? 'Dry run: %d execution(s) would be acted on. Nothing was changed.'
                : 'Pruned %d execution(s).',
            $report->touched(),
        ));

        if ($report->skipped > 0) {
            // Expected and harmless: the guard refusing to act on work
            // something else claimed is the guard doing its job.
            $this->components->info(sprintf(
                '%d execution(s) were skipped because they were claimed or re-executed mid-run.',
                $report->skipped,
            ));
        }

        if ($report->dryRun) {
            $this->components->info('Re-run without --dry-run to apply it.');
        }
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: string}|null
     */
    protected function row(string $tier, ?int $days, int $count, string $detail): ?array
    {
        if ($days === null) {
            return null;
        }

        return [$tier, "{$days}d", $count, $count === 0 ? '' : $detail];
    }

    /**
     * Leave a record of irreversible removals.
     *
     * The table output goes wherever the scheduler sends it, which may
     * be nowhere; deletions an operator cannot later account for are
     * exactly the ones they will want to account for.
     */
    protected function record(LoggerInterface $logger, RetentionReport $report): void
    {
        if ($report->dryRun || $report->isEmpty()) {
            return;
        }

        $logger->info('Laraflow pruned finished executions.', [
            'deleted' => $report->deleted,
            'deleted_failed' => $report->deletedFailed,
            'attempt_executions' => $report->attemptExecutions,
            'attempt_rows' => $report->attemptRows,
            'scrubbed' => $report->scrubbed,
            'skipped' => $report->skipped,
        ]);
    }
}
