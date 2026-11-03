<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
use Laraflow\Support\Outcome;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @internal The one way a console command creates an execution and runs
 * or dispatches it, shared by `laraflow:start` and `laraflow:demo`.
 *
 * The outcome reporting itself lives in DisplaysExecutions, with the
 * commands that run an execution they did not create.
 *
 * @mixin \Illuminate\Console\Command
 */
trait StartsExecutions
{
    use DisplaysExecutions;

    /**
     * Create the execution and run it in this process.
     *
     * The failure of a step is a command failure here, because the
     * caller asked for the run and did not get it; the persisted record
     * and the command that recovers it are printed with the error.
     *
     * @param  array<string, mixed>  $input
     */
    protected function startInline(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        Workflow $workflow,
        array $input,
        ?ExecutionOptions $options = null,
    ): int {
        $execution = $runner->start($workflow, $input, $options);

        try {
            $execution = $runner->execute($execution->id, $workflow);
        } catch (WorkflowException $exception) {
            // Not a step failure: the runtime refused the run itself —
            // another worker holds the claim, the claim was lost, the
            // definition no longer matches. The message names the thing
            // and the fix, so it is printed as it stands, as every other
            // command prints a WorkflowException.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->reportFailedRun($repository, $execution->id, $exception);

            return self::FAILURE;
        }

        $this->displayExecution($execution, $repository->steps($execution));

        return $this->reportOutcome($execution);
    }

    /**
     * Create the execution and hand it to the queue.
     *
     * start() + dispatchExecution() rather than dispatch(), so the
     * execution id is known even if the job throws — a sync queue
     * connection runs the job inside the dispatch call itself.
     *
     * @param  array<string, mixed>  $input
     */
    protected function startQueued(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowDispatcher $dispatcher,
        Workflow $workflow,
        array $input,
        ?ExecutionOptions $options = null,
    ): int {
        $execution = $runner->start($workflow, $input, $options);

        return $this->reportHandoff(
            Handoff::attempt($dispatcher, $repository, $execution),
            $repository,
        );
    }

    /**
     * Render what became of a handoff. The decision is Handoff's; this
     * is only how a console says it.
     */
    protected function reportHandoff(Handoff $handoff, ExecutionRepository $repository): int
    {
        if ($handoff->showsRecord()) {
            $this->displayExecution($handoff->execution, $repository->steps($handoff->execution));
        }

        match ($handoff->level) {
            Outcome::WARN => $this->components->warn($handoff->message),
            Outcome::ERROR => $this->components->error($handoff->message),
            default => $this->components->info($handoff->message),
        };

        if ($handoff->outcome === Handoff::STEP_FAILED) {
            $this->components->info(
                "The failure is persisted. Recover it with: laraflow:execute {$handoff->execution->id}"
            );
        }

        return $handoff->ok ? self::SUCCESS : self::FAILURE;
    }
}
