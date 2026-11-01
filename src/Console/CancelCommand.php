<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Workflow\WorkflowRunner;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Cancels an execution. Pending, failed, and retrying executions are
 * cancelled immediately, voiding any scheduled retry. A running
 * execution gets a cancellation request that its worker honours at the
 * next step boundary — the step in flight always finishes first.
 * Completed executions cannot be cancelled.
 */
class CancelCommand extends Command
{
    use DisplaysExecutions;
    use ParsesExecutionId;

    protected $signature = 'laraflow:cancel
        {execution : The id of the execution to cancel}
        {--reason= : Recorded on the execution and carried in the cancellation event}';

    protected $description = 'Cancel a workflow execution, or request cancellation from its running worker';

    public function handle(WorkflowRunner $runner): int
    {
        $reason = $this->option('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        try {
            $id = $this->executionIdArgument();
            $execution = $runner->cancel($id, $reason);
        } catch (WorkflowException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($execution->isCancelled()) {
            $this->components->info("Execution [{$id}] cancelled.");

            return self::SUCCESS;
        }

        $this->components->warn(
            "Execution [{$id}] is running. Cancellation was requested and its worker will stop at "
            .'the next step boundary; the step in flight finishes first. Watch it with: '
            ."laraflow:show {$id}"
        );

        return self::SUCCESS;
    }
}
