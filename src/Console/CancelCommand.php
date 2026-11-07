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
use Laraflow\Console\Concerns\SelectsActionable;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Support\JsonReport;
use Laraflow\Workflow\ExecutionReader;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @internal The command class is implementation. What 1.0 freezes is the
 * command's name, its options, and the shape of its `--json` document —
 * see docs/public-api.md. Nothing should type-hint or extend this class.
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
 * and cancels nothing. The bulk form selects only what it can act on:
 * completed and cancelled rows are never selected (SelectsActionable),
 * and a dry run predicts each row's outcome from its status rather
 * than promising to cancel all of them.
 */
final class CancelCommand extends Command
{
    use ConfirmableTrait;
    use DisplaysExecutions;
    use ParsesExecutionFilters;
    use ParsesExecutionId;
    use ParsesLimit;
    use SelectsActionable;

    protected $signature = 'laraflow:cancel
        {execution? : The id of the execution to cancel; omit it to cancel by filter}
        {--reason= : Recorded on the execution and carried in the cancellation event}
        {--status=* : Bulk: only executions with this status (never completed or cancelled); repeatable or comma-separated}
        {--workflow=* : Bulk: only executions created from this workflow class; repeatable or comma-separated}
        {--correlation= : Bulk: only executions with this correlation id}
        {--tenant= : Bulk: only executions for this tenant id}
        {--since= : Bulk: only executions created at or after this time (inclusive)}
        {--until= : Bulk: only executions created before this time (exclusive)}
        {--limit= : Bulk: the most executions one run cancels, oldest first (default 100)}
        {--dry-run : Bulk: predict each outcome, by status, without cancelling anything}
        {--force : Bulk: skip the production confirmation}
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Cancel a workflow execution, or request cancellation from its running worker';

    public function handle(WorkflowRunner $runner, ExecutionReader $repository): int
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

    protected function many(WorkflowRunner $runner, ExecutionReader $repository, ?string $reason): int
    {
        try {
            $requested = $this->filterFromOptions(oldestFirst: true);
            // Asked of the operator's own filter, before narrowing: the
            // narrowed one always carries statuses, so would always pass.
            $this->assertSomeCriterion($requested, 'cancel');
            $filter = $this->narrowToActionable($requested, 'cancel');
            $limit = $this->limitOption(100);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmBulk()) {
            return self::FAILURE;
        }

        // Counted before the page is read, so a row arriving between
        // the two reads cannot leave `selected` larger than `matched`.
        $total = $repository->count($filter);
        $targets = $total === 0 ? [] : $repository->list($filter, $limit)->executions;
        $total = max($total, count($targets));
        $actions = [];
        $counts = ['cancelled' => 0, 'requested' => 0, 'skipped' => 0, 'refused' => 0, 'failed' => 0];
        $predicted = ['would_cancel' => 0, 'would_request' => 0, 'already_cancelled' => 0, 'would_refuse' => 0];

        foreach ($targets as $target) {
            $actions[] = $action = $dryRun
                ? $this->predict($target)
                : $this->cancelOne($runner, $target, $reason);

            if ($dryRun) {
                $predicted[$action['outcome']]++;
            } else {
                $counts[$this->bucket($action['outcome'])]++;
            }
        }

        $ignored = $this->ignoredStatuses($requested);

        return $this->respond([
            'dry_run' => $dryRun,
            'matched' => $total,
            'selected' => count($targets),
            'ignored_statuses' => $ignored,
            'actions' => $actions,
            'counts' => $dryRun ? $predicted : $counts,
        ], function () use ($actions, $counts, $predicted, $total, $dryRun, $limit, $ignored): void {
            if ($ignored !== []) {
                $this->components->warn('Ignored --status '.implode(', ', $ignored).': completed and cancelled executions cannot be cancelled.');
            }

            if ($actions === []) {
                $this->components->info('No cancellable executions match that filter.');

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
                    'Dry run over %d of %d matching execution(s) (oldest first, up to --limit=%d): %d would be cancelled, '
                    .'%d would have cancellation requested from a running worker, %d are already cancelled, %d would be refused. Nothing was changed.',
                    count($actions),
                    $total,
                    $limit,
                    $predicted['would_cancel'],
                    $predicted['would_request'],
                    $predicted['already_cancelled'],
                    $predicted['would_refuse'],
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

            // A requested row is still running, and a refused or failed
            // one is unchanged: all three are selected again next time.
            $hint = $this->continuationHint($total, count($actions), $counts['requested'] + $counts['refused'] + $counts['failed']);

            if ($hint !== null) {
                $this->components->info($hint);
            }
        }, $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS);
    }

    /**
     * What the real run would do with $target, read from its status by
     * the same rules WorkflowRunner::cancel() applies — so a dry run
     * that says "would cancel" is not quietly a "requested" or a
     * refusal when it is run for real.
     *
     * @return array{execution: int, workflow: string, outcome: string, message: string|null}
     */
    protected function predict(WorkflowExecution $target): array
    {
        $row = ['execution' => $target->id, 'workflow' => $target->workflow];

        return match (true) {
            $target->isCancelled() => $row + ['outcome' => 'already_cancelled', 'message' => null],
            $target->isCompleted() => $row + ['outcome' => 'would_refuse', 'message' => "Execution [{$target->id}] has completed and cannot be cancelled."],
            $this->liveClaim($target) => $row + ['outcome' => 'would_request', 'message' => 'A live worker owns it; cancellation would be requested for its next step boundary.'],
            default => $row + ['outcome' => 'would_cancel', 'message' => null],
        };
    }

    /**
     * @return array{execution: int, workflow: string, outcome: string, message: string|null}
     */
    protected function cancelOne(WorkflowRunner $runner, WorkflowExecution $target, ?string $reason): array
    {
        $row = ['execution' => $target->id, 'workflow' => $target->workflow, 'outcome' => 'failed', 'message' => null];

        if ($target->isCancelled()) {
            // Idempotent, and said so: nothing happened here.
            return ['outcome' => 'already_cancelled'] + $row;
        }

        try {
            [$outcome, $message] = $this->describe($runner->cancel($target->id, $reason));

            return ['outcome' => $outcome, 'message' => $message] + $row;
        } catch (WorkflowException $exception) {
            // The runtime's refusal — completed, for one. Not a fault of
            // the run; the row says why and the rest of the batch goes on.
            return ['outcome' => 'refused', 'message' => $exception->getMessage()] + $row;
        } catch (Throwable $exception) {
            return ['outcome' => 'failed', 'message' => $this->redactedMessage($exception)] + $row;
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
            'would_cancel' => '<fg=cyan>would cancel</>',
            'would_request' => '<fg=yellow>would request from its worker</>',
            'would_refuse' => '<fg=gray>would be refused (not cancellable)</>',
            'already_cancelled' => '<fg=gray>already cancelled</>',
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
        if ($this->wantsJson() && ! (bool) $this->option('force') && $this->laravel->environment('production')) {
            // Exactly where the human form would prompt: a machine
            // cannot answer the production confirmation, and skipping
            // the question because nobody could hear it is not an
            // answer. Outside production neither form asks, and the two
            // exit codes stay identical.
            $this->refuse('A bulk cancel under --json must pass --force (or --dry-run) in production: a machine cannot answer the confirmation.');

            return false;
        }

        return $this->confirmToProceed('A bulk cancel changes durable records. Continue?');
    }
}
