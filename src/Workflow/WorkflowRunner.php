<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Support\DurableValue;
use Psr\Log\LoggerInterface;
use Throwable;

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
        protected Container $container,
        protected LoggerInterface $logger,
        protected int $staleAfterSeconds = 300,
        protected int $maxErrorLength = 65535,
        protected ?EventEmitter $events = null,
        protected bool $includeErrorMessages = true,
    ) {}

    /**
     * Create a pending execution and its complete step plan without
     * running anything.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidWorkflowDefinitionException if the workflow has no steps or a step is not a Step class.
     * @throws UnserializableValueException if the input is not JSON-serializable.
     */
    public function start(Workflow $workflow, array $input = []): WorkflowExecution
    {
        DurableValue::assert($input, 'input');

        return $this->repository->create(
            workflow: $workflow::class,
            input: $input,
            steps: $this->validatedPlan($workflow),
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

        $startedAt = $this->now();
        $execution->markRunning($startedAt);

        $this->event(new WorkflowExecutionStarted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            startedAt: $execution->startedAt ?? $startedAt,
        ));

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

            $this->event(new WorkflowStepStarted(
                executionId: $execution->id,
                workflow: $execution->workflow,
                step: $stepExecution->step,
                position: $stepExecution->position,
                attempt: $stepExecution->attempts,
            ));

            $context->enterStep($stepExecution->position, $stepExecution->attempts);

            try {
                $step = $this->makeStep($stepExecution->step);

                $output = $step->handle($context);

                DurableValue::assert($output, 'output');
                DurableValue::assert($context->all(), 'context');

                $completedAt = $this->now();
                $stepExecution->markCompleted($output, $completedAt);
                $execution->context = $context->all();

                $this->repository->checkpoint($execution, $stepExecution);

                $this->event(new WorkflowStepCompleted(
                    executionId: $execution->id,
                    workflow: $execution->workflow,
                    step: $stepExecution->step,
                    position: $stepExecution->position,
                    attempt: $stepExecution->attempts,
                    completedAt: $completedAt,
                ));
            } catch (ClaimLostException $exception) {
                // Another worker owns the execution now; nothing we
                // persist would be trustworthy, including a failure.
                throw $exception;
            } catch (Throwable $exception) {
                $this->persistFailure($execution, $stepExecution, $exception);

                throw $exception;
            }
        }

        $completedAt = $this->now();
        $execution->markCompleted($completedAt);
        $this->repository->release($execution);

        $this->event(new WorkflowExecutionCompleted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            completedAt: $completedAt,
        ));

        return $execution;
    }

    /**
     * Convenience: start() then execute() synchronously in this process.
     *
     * @param  array<string, mixed>  $input
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
     * @param  StepExecution[]  $stepExecutions
     */
    protected function assertDefinitionMatches(
        Workflow $workflow,
        WorkflowExecution $execution,
        array $stepExecutions
    ): void {
        if ($execution->workflow !== $workflow::class) {
            throw new WorkflowDefinitionMismatchException(
                "Execution [{$execution->id}] was created from [{$execution->workflow}], "
                .'not ['.$workflow::class.'].'
            );
        }

        $definedSteps = $workflow->steps();
        $persistedSteps = array_map(
            fn (StepExecution $step): string => $step->step,
            $stepExecutions
        );

        if ($definedSteps !== $persistedSteps) {
            throw new WorkflowDefinitionMismatchException(
                "The step plan persisted for execution [{$execution->id}] does not match "
                .'the current definition of ['.$workflow::class.']. '
                .'Persisted: ['.implode(', ', $persistedSteps).']. '
                .'Defined: ['.implode(', ', $definedSteps).']. '
                .'The workflow code has changed since this execution was created.'
            );
        }
    }

    /**
     * Validate the user-supplied step list before anything is persisted.
     * The declared return type of Workflow::steps() is a contract for
     * authors, not a guarantee, so the value is treated as untrusted here.
     *
     * @return list<class-string<Step>>
     */
    protected function validatedPlan(Workflow $workflow): array
    {
        return $this->validateSteps($workflow::class, $workflow->steps());
    }

    /**
     * @param  array<mixed>  $steps
     * @return list<class-string<Step>>
     */
    protected function validateSteps(string $workflow, array $steps): array
    {
        if ($steps === []) {
            throw InvalidWorkflowDefinitionException::noSteps($workflow);
        }

        $plan = [];

        foreach ($steps as $position => $step) {
            if (! is_string($step) || ! class_exists($step) || ! is_subclass_of($step, Step::class)) {
                throw InvalidWorkflowDefinitionException::invalidStep($workflow, $position, $step);
            }

            $plan[] = $step;
        }

        return $plan;
    }

    protected function makeStep(string $stepClass): Step
    {
        if (! class_exists($stepClass)) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] no longer exists."
            );
        }

        $step = $this->container->make($stepClass);

        if (! $step instanceof Step) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] does not implement ".Step::class.'.'
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
        Throwable $exception
    ): void {
        $error = mb_substr($exception->getMessage(), 0, $this->maxErrorLength);
        $failedAt = $this->now();

        try {
            $stepExecution->markFailed($error, $exception::class, $failedAt);
            $this->repository->saveStep($stepExecution);

            $execution->markFailed($error, $exception::class, $failedAt);
            $this->repository->release($execution);
        } catch (Throwable $storageFailure) {
            $this->logger->error('Laraflow could not persist a workflow failure.', [
                'execution_id' => $execution->id,
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
                'original_exception' => $exception::class.': '.$exception->getMessage(),
                'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
            ]);

            // Events describe durable state; none exists to describe.
            return;
        }

        $eventError = $this->includeErrorMessages ? $error : null;

        $this->event(new WorkflowStepFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
            errorClass: $exception::class,
            error: $eventError,
        ));

        $this->event(new WorkflowExecutionFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            errorClass: $exception::class,
            error: $eventError,
            failedAt: $failedAt,
        ));
    }

    /**
     * Fire a lifecycle event, if an emitter was provided. Emission is
     * observational and never affects execution state; see EventEmitter.
     */
    protected function event(object $event): void
    {
        $this->events?->emit($event);
    }

    /**
     * Uses Laravel's Date facade so Date::setTestNow() controls every
     * timestamp the runner writes.
     */
    protected function now(): DateTimeImmutable
    {
        return Date::now()->toImmutable();
    }
}
