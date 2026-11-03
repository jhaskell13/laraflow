<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionFilters;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Console\Concerns\ParsesLimit;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Support\JsonReport;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Cancels an execution. Pending, failed, retrying, and waiting
 * executions are cancelled immediately, voiding any scheduled retry. A
 * running execution gets a cancellation request that its worker honours
 * at the next step boundary — the step in flight always finishes first.
 * Completed executions cannot be cancelled.
 *
 * Without an id, cancels every execution a filter matches, oldest
 * first, up to --limit — the console's bulk form. A filter is required
 * (there is no "cancel everything"), the production confirmation
 * applies unless --force, and --dry-run names what would be cancelled
 * and cancels nothing.
 */
class CancelCommand extends Command
{
    use ConfirmableTrait;
    use DisplaysExecutions;
    use ParsesExecutionFilters;
    use ParsesExecutionId;
    use ParsesLimit;

    protected $signature = 'laraflow:cancel
        {execution? : The id of the execution to cancel; omit it to cancel by filter}
        {--reason= : Recorded on the execution and carried in the cancellation event}
        {--status=* : Bulk: only executions with this status; repeatable}
        {--workflow=* : Bulk: only executions created from this workflow class; repeatable}
        {--correlation= : Bulk: only executions with this correlation id}
        {--tenant= : Bulk: only executions for this tenant id}
        {--since= : Bulk: only executions created at or after this time (inclusive)}
        {--until= : Bulk: only executions created before this time (exclusive)}
        {--limit= : Bulk: the most executions one run cancels, oldest first (default 100)}
        {--dry-run : Bulk: name what would be cancelled without cancelling anything}
        {--force : Bulk: skip the production confirmation}
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Cancel a workflow execution, or request cancellation from its running worker';

    public function handle(WorkflowRunner $runner, ExecutionRepository $repository): int
    {
        $reason = $this->stringOption('reason');

        if ($this->argument('execution') !== null) {
            return $this->one($runner, $reason);
        }

        return $this->many($runner, $repository, $reason);
    }

    protected function one(WorkflowRunner $runner, ?string $reason): int
    {
        try {
            $id = $this->executionIdArgument();
            $this->assertNoFilter();
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        try {
            $execution = $runner->cancel($id, $reason);
        } catch (WorkflowException $exception) {
            return $this->refuse($exception->getMessage(), ['execution' => $id]);
        }

        [$outcome, $message] = $this->describe($execution);

        return $this->respond(
            ['execution' => JsonReport::execution($execution), 'outcome' => $outcome, 'message' => $message],
            fn () => $outcome === 'requested' ? $this->components->warn($message) : $this->components->info($message),
        );
    }

    protected function many(WorkflowRunner $runner, ExecutionRepository $repository, ?string $reason): int
    {
        try {
            $filter = $this->filterFromOptions(oldestFirst: true);
            $this->assertSomeCriterion($filter, 'cancel');
            $limit = $this->limitOption(100);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmBulk()) {
            return self::FAILURE;
        }

        $targets = $repository->list($filter, $limit)->executions;
        $total = $repository->count($filter);
        $actions = [];
        $counts = ['cancelled' => 0, 'requested' => 0, 'skipped' => 0, 'refused' => 0, 'failed' => 0];

        foreach ($targets as $target) {
            $actions[] = $action = $dryRun
                ? ['execution' => $target->id, 'workflow' => $target->workflow, 'outcome' => 'would cancel', 'message' => null]
                : $this->cancelOne($runner, $target, $reason);

            if (! $dryRun) {
                $counts[$this->bucket($action['outcome'])]++;
            }
        }

        return $this->respond([
            'dry_run' => $dryRun,
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
                ['Id', 'Workflow', 'Outcome'],
                array_map(fn (array $action): array => [
                    $action['execution'],
                    class_basename($action['workflow']),
                    $this->coloredBulkOutcome($action['outcome']),
                ], $actions),
            );

            if ($dryRun) {
                $this->components->info(sprintf(
                    'Dry run: %d of %d matching execution(s) would be cancelled (oldest first, up to --limit=%d). Nothing was changed.',
                    count($actions),
                    $total,
                    $limit,
                ));

                return;
            }

            $this->components->info(sprintf(
                'Handled %d of %d matching execution(s): %d cancelled, %d requested from a running worker, %d skipped, %d refused, %d failed.',
                count($actions),
                $total,
                $counts['cancelled'],
                $counts['requested'],
                $counts['skipped'],
                $counts['refused'],
                $counts['failed'],
            ));

            if ($total > count($actions)) {
                $this->components->info('More matching executions exist; run the same command again to continue.');
            }
        }, $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS);
    }

    /**
     * @return array{execution: int, workflow: string, outcome: string, message: string|null}
     */
    protected function cancelOne(WorkflowRunner $runner, WorkflowExecution $target, ?string $reason): array
    {
        $row = ['execution' => $target->id, 'workflow' => $target->workflow, 'outcome' => 'failed', 'message' => null];

        if ($target->isCancelled()) {
            // Idempotent, and said so: nothing happened here.
            return ['outcome' => 'already cancelled'] + $row;
        }

        try {
            [$outcome, $message] = $this->describe($runner->cancel($target->id, $reason));

            return ['outcome' => $outcome, 'message' => $message] + $row;
        } catch (WorkflowException $exception) {
            // The runtime's refusal — completed, for one. Not a fault of
            // the run; the row says why and the rest of the batch goes on.
            return ['outcome' => 'refused', 'message' => $exception->getMessage()] + $row;
        } catch (Throwable $exception) {
            return ['outcome' => 'failed', 'message' => $exception->getMessage()] + $row;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function describe(WorkflowExecution $execution): array
    {
        if ($execution->isCancelled()) {
            return ['cancelled', "Execution [{$execution->id}] cancelled."];
        }

        return ['requested', "Execution [{$execution->id}] is running. Cancellation was requested and its worker will stop at "
            .'the next step boundary; the step in flight finishes first. Watch it with: '
            ."laraflow:show {$execution->id}"];
    }

    protected function bucket(string $outcome): string
    {
        return match ($outcome) {
            'cancelled', 'requested', 'refused', 'failed' => $outcome,
            default => 'skipped',
        };
    }

    protected function coloredBulkOutcome(string $outcome): string
    {
        return match ($outcome) {
            'cancelled' => '<fg=green>cancelled</>',
            'requested' => '<fg=yellow>requested from its worker</>',
            'would cancel' => '<fg=cyan>would cancel</>',
            'refused' => '<fg=gray>refused (not cancellable)</>',
            'failed' => '<fg=red>failed</>',
            default => "<fg=gray>{$outcome}</>",
        };
    }

    /**
     * A bulk cancel in production asks first, exactly as laraflow:prune
     * does; --force is how a script says yes. A --json run cannot be
     * asked anything, so it must say --force or --dry-run itself rather
     * than hang a pipeline on a prompt.
     */
    protected function confirmBulk(): bool
    {
        if ($this->wantsJson() && ! (bool) $this->option('force')) {
            $this->refuse('A bulk cancel under --json must pass --force (or --dry-run): a machine cannot answer the production confirmation.');

            return false;
        }

        return $this->confirmToProceed('A bulk cancel changes durable records. Continue?');
    }
}
