<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Console\Concerns\ParsesLimit;
use Laraflow\Console\Concerns\StartsExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
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
 * refusal or failure never stops the rest.
 */
class ExecuteCommand extends Command
{
    use ConfirmableTrait;
    use ParsesExecutionFilters;
    use ParsesExecutionId;
    use ParsesLimit;
    use StartsExecutions;

    protected $signature = 'laraflow:execute
        {execution? : The id of the execution to run or recover; omit it to re-run by filter}
        {--queue : Dispatch to the queue instead of running in this process}
        {--now : Run a retrying execution, or resume a timed wait, immediately — ignoring its schedule}
        {--status=* : Bulk: only executions with this status; repeatable}
        {--workflow=* : Bulk: only executions created from this workflow class; repeatable}
        {--correlation= : Bulk: only executions with this correlation id}
        {--tenant= : Bulk: only executions for this tenant id}
        {--since= : Bulk: only executions created at or after this time (inclusive)}
        {--until= : Bulk: only executions created before this time (exclusive)}
        {--limit= : Bulk: the most executions one run acts on, oldest first (default 100)}
        {--dry-run : Bulk: name what would run without running anything}
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
            $filter = $this->filterFromOptions(oldestFirst: true);
            $this->assertSomeCriterion($filter, 're-run');
            $limit = $this->limitOption(100);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $dryRun = (bool) $this->option('dry-run');
        $queue = (bool) $this->option('queue');

        if (! $dryRun && ! $this->confirmBulk()) {
            return self::FAILURE;
        }

        $targets = $repository->list($filter, $limit)->executions;
        $total = $repository->count($filter);
        $actions = [];
        $counts = ['ran' => 0, 'queued' => 0, 'refused' => 0, 'failed' => 0];

        foreach ($targets as $target) {
            $actions[] = $action = $dryRun
                ? ['execution' => $target->id, 'workflow' => $target->workflow, 'outcome' => $queue ? 'would_queue' : 'would_run', 'message' => null]
                : $this->executeOne($runner, $repository, $resolver, $dispatcher, $target, $queue);

            if (! $dryRun) {
                $counts[$this->bucket($action['outcome'])]++;
            }
        }

        return $this->respond([
            'dry_run' => $dryRun,
            'queue' => $queue,
            'matched' => $total,
            'selected' => count($targets),
            'actions' => $actions,
            'counts' => $counts,
        ], function () use ($actions, $counts, $total, $dryRun, $limit): void {
            if ($actions === []) {
                $this->components->info('No executions match that filter.');

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
                    'Dry run: %d of %d matching execution(s) would be acted on (oldest first, up to --limit=%d). Nothing was changed.',
                    count($actions),
                    $total,
                    $limit,
                ));

                return;
            }

            $this->components->info(sprintf(
                'Handled %d of %d matching execution(s): %d ran, %d queued, %d refused, %d failed.',
                count($actions),
                $total,
                $counts['ran'],
                $counts['queued'],
                $counts['refused'],
                $counts['failed'],
            ));

            if ($counts['failed'] > 0) {
                $this->components->info('Failures are persisted on their executions; each can be recovered again with laraflow:execute <id>.');
            }

            if ($total > count($actions)) {
                $this->components->info('More matching executions exist; run the same command again to continue.');
            }
        }, $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS);
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

                return [
                    'outcome' => $handoff->ok ? ($handoff->outcome === Handoff::RAN ? $handoff->execution->status->value : 'queued') : ($handoff->outcome === Handoff::REFUSED ? 'refused' : 'failed'),
                    'message' => $handoff->ok ? null : $handoff->message,
                ] + $row;
            }

            $result = $runner->execute($target->id, $workflow, ignoreRetrySchedule: (bool) $this->option('now'));

            return ['outcome' => $result->status->value, 'message' => null] + $row;
        } catch (WorkflowException $exception) {
            // Claimed elsewhere, not runnable, a definition that no
            // longer matches: the runtime's refusal, not a failure of
            // the run.
            return ['outcome' => 'refused', 'message' => $exception->getMessage()] + $row;
        } catch (Throwable $exception) {
            // A step failed: persisted on the execution, and the rest of
            // the batch deserves its attempt.
            return ['outcome' => 'failed', 'message' => $exception->getMessage()] + $row;
        }
    }

    protected function bucket(string $outcome): string
    {
        return match ($outcome) {
            'queued' => 'queued',
            'refused' => 'refused',
            'failed' => 'failed',
            default => 'ran',
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
