<?php

namespace Laraflow\Workflow;

use Illuminate\Support\Facades\Log;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Support\DurableValue;

/**
 * Runs workflows against durable execution state.
 *
 * The lifecycle contract implemented here is documented in
 * docs/execution-lifecycle.md. In short: start() persists the execution
 * and its full step plan, execute() claims the execution and runs every
 * step that is not yet completed, checkpointing output and context after
 * each step, and run() is the synchronous combination of the two.
 */
class WorkflowRunner
{
    public function __construct(
        protected ExecutionRepository $repository,
        protected int $staleAfterSeconds = 300,
        protected int $maxErrorLength = 65535,
    ) {
    }

    /**
     * Create a pending execution and its complete step plan without
     * running anything.
     *
     * @throws UnserializableValueException if the input is not JSON-serializable.
     */
    public function start(Workflow $workflow, array $input = []): WorkflowExecution
    {
        DurableValue::assert($input, 'input');

        return $this->repository->create(
            workflow: $workflow::class,
            input: $input,
            steps: array_values($workflow->steps()),
        );
    }

    /**
     * Claim an existing execution and run every step that has not
     * completed, in order. Used both for first execution and for
     * recovery after a failure or worker loss.
     *
     * Completed steps are skipped; their results reach later steps via
     * the restored context. Steps found in `running` state are treated
     * as interrupted and run again (the at-least-once boundary).
     *
     * On step failure the failure is persisted and the step's exception
     * is rethrown unchanged.
     */
    public function execute(int|string $executionId, Workflow $workflow): WorkflowExecution
    {
        $claimToken = bin2hex(random_bytes(16));

        $execution = $this->repository->claim(
            $executionId,
            $claimToken,
            $this->staleAfterSeconds
        );

        $execution->markRunning($this->now());

        $steps = $this->repository->steps($execution);

        $this->assertDefinitionMatches($workflow, $execution, $steps);

        $context = new WorkflowContext(
            data: array_merge($execution->input, $execution->context),
            executionId: $execution->id,
        );

        foreach ($steps as $stepExecution) {
            if ($stepExecution->isCompleted()) {
                continue;
            }

            $stepExecution->markRunning($this->now());
            $this->repository->saveStep($stepExecution);

            $context->enterStep($stepExecution->position, $stepExecution->attempts);

            try {
                $step = $this->makeStep($stepExecution->step);

                $output = $step->handle($context);

                DurableValue::assert($output, 'output');
                DurableValue::assert($context->all(), 'context');

                $stepExecution->markCompleted($output, $this->now());
                $execution->context = $context->all();

                $this->repository->checkpoint($execution, $stepExecution);
            } catch (ClaimLostException $exception) {
                // Another worker owns the execution now; nothing we
                // persist would be trustworthy, including a failure.
                throw $exception;
            } catch (\Throwable $exception) {
                $this->persistFailure($execution, $stepExecution, $exception);

                throw $exception;
            }
        }

        $execution->markCompleted($this->now());
        $this->repository->release($execution);

        return $execution;
    }

    /**
     * Convenience: start() then execute() synchronously in this process.
     */
    public function run(Workflow $workflow, array $input = []): WorkflowExecution
    {
        $execution = $this->start($workflow, $input);

        return $this->execute($execution->id, $workflow);
    }

    /**
     * The supplied workflow must be the one the execution was created
     * from: same class, same steps, same order. Otherwise durable state
     * would be silently reinterpreted by different code.
     *
     * @param StepExecution[] $stepExecutions
     */
    protected function assertDefinitionMatches(
        Workflow $workflow,
        WorkflowExecution $execution,
        array $stepExecutions
    ): void {
        if ($execution->workflow !== $workflow::class) {
            throw new WorkflowDefinitionMismatchException(
                "Execution [{$execution->id}] was created from [{$execution->workflow}], "
                . 'not [' . $workflow::class . '].'
            );
        }

        $definedSteps = array_values($workflow->steps());
        $persistedSteps = array_map(
            fn (StepExecution $step) => $step->step,
            $stepExecutions
        );

        if ($definedSteps !== $persistedSteps) {
            throw new WorkflowDefinitionMismatchException(
                "The step plan persisted for execution [{$execution->id}] does not match "
                . 'the current definition of [' . $workflow::class . ']. '
                . 'Persisted: [' . implode(', ', $persistedSteps) . ']. '
                . 'Defined: [' . implode(', ', $definedSteps) . ']. '
                . 'The workflow code has changed since this execution was created.'
            );
        }
    }

    protected function makeStep(string $stepClass): Step
    {
        if (! class_exists($stepClass)) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] no longer exists."
            );
        }

        $step = app($stepClass);

        if (! $step instanceof Step) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] does not implement " . Step::class . '.'
            );
        }

        return $step;
    }

    /**
     * Persist a step failure and the execution's failed state. If the
     * persistence itself fails, the original step exception still
     * propagates; the storage failure is logged instead of replacing it.
     */
    protected function persistFailure(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        \Throwable $exception
    ): void {
        $error = mb_substr($exception->getMessage(), 0, $this->maxErrorLength);

        try {
            $stepExecution->markFailed($error, $exception::class, $this->now());
            $this->repository->saveStep($stepExecution);

            $execution->markFailed($error, $exception::class, $this->now());
            $this->repository->release($execution);
        } catch (\Throwable $storageFailure) {
            Log::error('Laraflow could not persist a workflow failure.', [
                'execution_id' => $execution->id,
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
                'original_exception' => $exception::class . ': ' . $exception->getMessage(),
                'storage_exception' => $storageFailure::class . ': ' . $storageFailure->getMessage(),
            ]);
        }
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
