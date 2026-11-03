<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
use Laraflow\Support\JsonReport;
use Laraflow\Support\Outcome;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @internal The one way a console command creates an execution and runs
 * or dispatches it, shared by `laraflow:start` and `laraflow:demo`; and
 * the one way a handoff to the queue is reported, shared with
 * `laraflow:execute` and `laraflow:signal`.
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
            return $this->refuse($exception->getMessage(), ['execution' => $execution->id]);
        } catch (Throwable $exception) {
            return $this->reportFailedRun($repository, $execution->id, $exception);
        }

        return $this->reportRun($execution, $repository->steps($execution));
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
     * is only how a console says it — in prose, or as a document whose
     * `handoff` is Handoff's own outcome word.
     *
     * @param  array<string, mixed>  $extra  Fields a calling command adds to the document.
     */
    protected function reportHandoff(Handoff $handoff, ExecutionRepository $repository, array $extra = []): int
    {
        $steps = $handoff->showsRecord() ? $repository->steps($handoff->execution) : null;

        $document = $extra + [
            'execution' => JsonReport::execution($handoff->execution)
                + ($steps === null ? [] : ['steps' => array_map(JsonReport::step(...), $steps)]),
            'handoff' => $handoff->outcome,
            'message' => $handoff->message,
        ];

        return $this->respond($document, function () use ($handoff, $steps): void {
            if ($steps !== null) {
                $this->displayExecution($handoff->execution, $steps);
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
        }, $handoff->ok ? self::SUCCESS : self::FAILURE);
    }

    /**
     * @param  StepExecution[]  $steps
     */
    protected function reportRun(\Laraflow\Workflow\WorkflowExecution $execution, array $steps): int
    {
        ['message' => $message, 'ok' => $ok] = Outcome::describe($execution);

        $document = [
            'execution' => JsonReport::execution($execution) + ['steps' => array_map(JsonReport::step(...), $steps)],
            'outcome' => $execution->status->value,
            'message' => $message,
        ];

        return $this->respond($document, function () use ($execution, $steps): void {
            $this->displayExecution($execution, $steps);
            $this->reportOutcome($execution);
        }, $ok ? self::SUCCESS : self::FAILURE);
    }
}
