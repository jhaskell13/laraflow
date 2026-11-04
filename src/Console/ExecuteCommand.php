<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Console\Concerns\ParsesLimit;
use Laraflow\Console\Concerns\SelectsActionable;
use Laraflow\Console\Concerns\StartsExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
use Laraflow\Support\Outcome;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowResolver;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Executes (or re-executes) a persisted execution using the workflow class
 * recorded on it: pending executions run for the first time, failed ones
 * retry from their last checkpoint, stale running ones are reclaimed.
 * With --queue the execution is handed to a queue worker instead of
 * running in this process.
 *
 * Without an id, re-runs every execution a filter matches, oldest
 * first, up to --limit — the console's bulk form, for the morning after
 * an outage left a hundred executions failed. A filter is required, the
 * production confirmation applies unless --force, and --dry-run names
 * what would run and runs nothing. Each row is its own attempt: one
 * refusal or failure never stops the rest. Completed and cancelled rows
 * are never selected (SelectsActionable), and a dry run predicts each
 * row from its status — a live claim or a retry not yet due is a
 * would_refuse, not a would_run.
 */
class ExecuteCommand extends Command
{
    use ConfirmableTrait;
    use ParsesExecutionFilters;
    use ParsesExecutionId;
    use ParsesLimit;
    use SelectsActionable;
    use StartsExecutions;

    protected $signature = 'laraflow:execute
        {execution? : The id of the execution to run or recover; omit it to re-run by filter}
        {--queue : Dispatch to the queue instead of running in this process}
        {--now : Run a retrying execution, or resume a timed wait, immediately — ignoring its schedule}
        {--status=* : Bulk: only executions with this status (never completed or cancelled); repeatable or comma-separated}
        {--workflow=* : Bulk: only executions created from this workflow class; repeatable or comma-separated}
        {--correlation= : Bulk: only executions with this correlation id}
        {--tenant= : Bulk: only executions for this tenant id}
        {--since= : Bulk: only executions created at or after this time (inclusive)}
        {--until= : Bulk: only executions created before this time (exclusive)}
        {--limit= : Bulk: the most executions one run acts on, oldest first (default 100)}
        {--dry-run : Bulk: predict each outcome, by status, without running anything}
        {--force : Bulk: skip the production confirmation}
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Execute or recover a persisted workflow execution';

    public function handle(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowResolver $resolver,
        WorkflowDispatcher $dispatcher,
    ): int {
        if ($this->argument('execution') !== null) {
            return $this->one($runner, $repository, $resolver, $dispatcher);
        }

        return $this->many($runner, $repository, $resolver, $dispatcher);
    }

    protected function one(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowResolver $resolver,
        WorkflowDispatcher $dispatcher,
    ): int {
        try {
            $id = $this->executionIdArgument();
            $this->assertNoFilter();
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        try {
            $execution = $repository->find($id);
            // Resolve up front in both modes: a missing workflow class
            // should fail here, not minutes later on a queue worker.
            $workflow = $resolver->resolve($execution->id, $execution->workflow);
        } catch (WorkflowException $exception) {
            return $this->refuse($exception->getMessage(), ['execution' => $id]);
        }

        if ((bool) $this->option('queue')) {
            return $this->reportHandoff(Handoff::attempt($dispatcher, $repository, $execution), $repository);
        }

        if (! $this->wantsJson()) {
            $this->components->info(
                'Executing ['.$id.'] with ['.$execution->workflow.']. Completed steps will be skipped.'
            );
        }

        try {
            $execution = $runner->execute($execution->id, $workflow, ignoreRetrySchedule: (bool) $this->option('now'));
        } catch (WorkflowException $exception) {
            return $this->refuse($exception->getMessage(), ['execution' => $id]);
        } catch (Throwable $exception) {
            // reportFailedRun() redacts the message through the
            // configured ErrorRedactor, as the execution row did.
            return $this->reportFailedRun($repository, $id, $exception);
        }

        // Every outcome — retrying, waiting, cancelled, completed — is
        // said by the one reporter every running command shares.
        return $this->reportRun($execution, $repository->steps($execution));
    }

    protected function many(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowResolver $resolver,
        WorkflowDispatcher $dispatcher,
    ): int {
        try {
            $requested = $this->filterFromOptions(oldestFirst: true);
            // Asked of the operator's own filter, before narrowing: the
            // narrowed one always carries statuses, so would always pass.
            $this->assertSomeCriterion($requested, 're-run');
            $filter = $this->narrowToActionable($requested, 're-run');
            $limit = $this->limitOption(100);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $dryRun = (bool) $this->option('dry-run');
        $queue = (bool) $this->option('queue');

        if (! $dryRun && ! $this->confirmBulk()) {
            return self::FAILURE;
        }

        // Counted before the page is read, so a row arriving between
        // the two reads cannot leave `selected` larger than `matched`.
        $total = $repository->count($filter);
        $targets = $total === 0 ? [] : $repository->list($filter, $limit)->executions;
        $total = max($total, count($targets));
        $actions = [];
        $counts = ['ran' => 0, 'queued' => 0, 'cancelled' => 0, 'refused' => 0, 'failed' => 0];
        $predicted = ['would_run' => 0, 'would_queue' => 0, 'would_refuse' => 0];

        foreach ($targets as $target) {
            $actions[] = $action = $dryRun
                ? $this->predict($target, $queue)
                : $this->executeOne($runner, $repository, $resolver, $dispatcher, $target, $queue);

            if ($dryRun) {
                $predicted[$action['outcome']]++;
            } else {
                $counts[$this->bucket($action['outcome'])]++;
            }
        }

        $ignored = $this->ignoredStatuses($requested);

        return $this->respond([
            'dry_run' => $dryRun,
            'queue' => $queue,
            'matched' => $total,
            'selected' => count($targets),
            'ignored_statuses' => $ignored,
            'actions' => $actions,
            'counts' => $dryRun ? $predicted : $counts,
        ], function () use ($actions, $counts, $predicted, $total, $dryRun, $limit, $ignored): void {
            if ($ignored !== []) {
                $this->components->warn('Ignored --status '.implode(', ', $ignored).': completed and cancelled executions cannot be run again.');
            }

            if ($actions === []) {
                $this->components->info('No runnable executions match that filter.');

                return;
            }

            $this->table(
                ['Id', 'Workflow', 'Outcome', 'Detail'],
                array_map(fn (array $action): array => [
                    $action['execution'],
                    class_basename($action['workflow']),
                    $this->coloredBulkOutcome($action['outcome']),
                    $action['message'] === null ? '' : $this->formatValue($action['message'], 60),
                ], $actions),
            );

            if ($dryRun) {
                $this->components->info(sprintf(
                    'Dry run over %d of %d matching execution(s) (oldest first, up to --limit=%d): %d would run, %d would be queued, %d would be refused. Nothing was changed.',
                    count($actions),
                    $total,
                    $limit,
                    $predicted['would_run'],
                    $predicted['would_queue'],
                    $predicted['would_refuse'],
                ));

                return;
            }

            $this->components->info(sprintf(
                'Handled %d of %d matching execution(s): %d ran, %d queued, %d cancelled, %d refused, %d failed.',
                count($actions),
                $total,
                $counts['ran'],
                $counts['queued'],
                $counts['cancelled'],
                $counts['refused'],
                $counts['failed'],
            ));

            if ($counts['failed'] > 0) {
                $this->components->info('Failures are persisted on their executions; each can be recovered again with laraflow:execute <id>.');
            }

            // A refused or failed row is unchanged — or failed again —
            // and so is selected first again next time.
            $hint = $this->continuationHint($total, count($actions), $counts['refused'] + $counts['failed']);

            if ($hint !== null) {
                $this->components->info($hint);
            }
        }, $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS);
    }

    /**
     * What the real run would meet, by the rules claim() applies: a
     * live claim is refused, a retry or timed wait not yet due is
     * refused unless --now, and anything else is claimable. Queued, the
     * dispatcher refuses only the terminal statuses — the job meets the
     * rest on a worker — so only those are predicted refused.
     *
     * @return array{execution: int, workflow: string, outcome: string, message: string|null}
     */
    protected function predict(WorkflowExecution $target, bool $queue): array
    {
        $row = ['execution' => $target->id, 'workflow' => $target->workflow];
        $go = $row + ['outcome' => $queue ? 'would_queue' : 'would_run', 'message' => null];
        $refuse = fn (string $why): array => $row + ['outcome' => 'would_refuse', 'message' => $why];

        if ($target->isCompleted() || $target->isCancelled()) {
            return $refuse("Execution [{$target->id}] is {$target->status->value} and cannot run again.");
        }

        if ($queue) {
            return $go;
        }

        $now = $this->now();
        $override = (bool) $this->option('now');

        return match (true) {
            $this->liveClaim($target) => $refuse('A live worker owns it.'),
            $target->isRetrying() && ! $override && $target->nextRetryAt !== null && $target->nextRetryAt > $now => $refuse('Its retry is not due until '.$this->formatDate($target->nextRetryAt).'; pass --now to run it anyway.'),
            $target->isWaiting() && ! $override && ($target->nextRetryAt === null || $target->nextRetryAt > $now) => $refuse(
                $target->nextRetryAt === null
                    ? 'It waits for a signal; deliver one with laraflow:signal.'
                    : 'Its wait is not due until '.$this->formatDate($target->nextRetryAt).'; pass --now to resume it anyway.'
            ),
            default => $go,
        };
    }

    /**
     * @return array{execution: int, workflow: string, outcome: string, message: string|null}
     */
    protected function executeOne(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowResolver $resolver,
        WorkflowDispatcher $dispatcher,
        WorkflowExecution $target,
        bool $queue,
    ): array {
        $row = ['execution' => $target->id, 'workflow' => $target->workflow, 'outcome' => 'failed', 'message' => null];

        try {
            $workflow = $resolver->resolve($target->id, $target->workflow);

            if ($queue) {
                $handoff = Handoff::attempt($dispatcher, $repository, $target);

                return match ($handoff->outcome) {
                    // Pushed: a worker owns what happens next.
                    Handoff::QUEUED => ['outcome' => 'queued', 'message' => null] + $row,
                    // A sync connection ran it here: the row says what
                    // it came to, exactly as an in-process run would.
                    Handoff::RAN => ['outcome' => $handoff->execution->status->value, 'message' => $handoff->message] + $row,
                    Handoff::REFUSED => ['outcome' => 'refused', 'message' => $handoff->message] + $row,
                    default => ['outcome' => 'failed', 'message' => $handoff->message] + $row,
                };
            }

            $result = $runner->execute($target->id, $workflow, ignoreRetrySchedule: (bool) $this->option('now'));

            return ['outcome' => $result->status->value, 'message' => Outcome::describe($result)['message']] + $row;
        } catch (WorkflowException $exception) {
            // Claimed elsewhere, not runnable, a definition that no
            // longer matches: the runtime's refusal, not a failure of
            // the run.
            return ['outcome' => 'refused', 'message' => $exception->getMessage()] + $row;
        } catch (Throwable $exception) {
            // A step failed: persisted on the execution, and the rest of
            // the batch deserves its attempt.
            return ['outcome' => 'failed', 'message' => $this->redactedMessage($exception)] + $row;
        }
    }

    /**
     * A row's outcome is the status the execution came to, or a word of
     * this command's own. Only completed, retrying, and waiting are
     * "ran" — it moved forward and its own schedule owns the rest. A
     * cancellation honoured mid-run is its own bucket, and anything
     * else (failed, or a status no run should leave behind) is counted
     * as a failure rather than quietly as progress (Milestone 10 audit).
     */
    protected function bucket(string $outcome): string
    {
        return match ($outcome) {
            'completed', 'retrying', 'waiting' => 'ran',
            'queued' => 'queued',
            'cancelled' => 'cancelled',
            'refused' => 'refused',
            default => 'failed',
        };
    }

    protected function coloredBulkOutcome(string $outcome): string
    {
        return match ($outcome) {
            'completed' => '<fg=green>completed</>',
            'retrying', 'waiting' => "<fg=cyan>{$outcome}</>",
            'queued' => '<fg=green>queued</>',
            'would_run' => '<fg=cyan>would run</>',
            'would_queue' => '<fg=cyan>would queue</>',
            'would_refuse' => '<fg=gray>would be refused</>',
            'cancelled' => '<fg=magenta>cancelled</>',
            'refused' => '<fg=gray>refused</>',
            'failed' => '<fg=red>failed</>',
            default => "<fg=gray>{$outcome}</>",
        };
    }

    protected function confirmBulk(): bool
    {
        if ($this->wantsJson() && ! (bool) $this->option('force') && $this->laravel->environment('production')) {
            // Exactly where the human form would prompt: a machine
            // cannot answer the production confirmation, and skipping
            // the question because nobody could hear it is not an
            // answer. Outside production neither form asks, and the two
            // exit codes stay identical.
            $this->refuse('A bulk re-run under --json must pass --force (or --dry-run) in production: a machine cannot answer the confirmation.');

            return false;
        }

        return $this->confirmToProceed('A bulk re-run claims and runs durable work. Continue?');
    }
}
