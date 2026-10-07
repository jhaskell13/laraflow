<?php

namespace Laraflow\Workflow;

class WorkflowRunner
{
    public function __construct(protected ExecutionRepository $repository)
    {

    }

    public function run(Workflow $workflow): WorkflowContext
    {
        $execution = $this->repository->create(workflow: $workflow::class);

        $execution->status = ExecutionStatus::Running;
        $execution->startedAt = new \DateTimeImmutable();

        $this->repository->save($execution);

        $context = new WorkflowContext();

        foreach ($workflow->steps() as $position => $stepClass) {
            $stepExecution = $this->repository->createStep(
                execution: $execution,
                step: $stepClass,
                position: $position,
            );

            $stepExecution->status = StepStatus::Running;
            $stepExecution->attempts++;
            $stepExecution->startedAt = new \DateTimeImmutable();

            $this->repository->saveStep($stepExecution);

            $step = app($stepClass);

            try {
                $output = $step->handle($context);
                
                $stepExecution->status = StepStatus::Completed;
                $stepExecution->output = $output;
                $stepExecution->completedAt = new \DateTimeImmutable();
                
                $this->repository->saveStep($stepExecution);
            } catch (\Throwable $exception) {
                $stepExecution->status = StepStatus::Failed;
                $stepExecution->error = $exception->getMessage();

                $this->repository->saveStep($stepExecution);

                $execution->status = ExecutionStatus::Failed;
                $execution->failedAt = new \DateTimeImmutable();

                $this->repository->save($execution);

                throw $exception;
            }
        }

        $execution->status = ExecutionStatus::Completed;
        $execution->completedAt = new \DateTimeImmutable();

        $this->repository->save($execution);

        return $context;
    }

    public function resume(
        int|string $executionId,
        Workflow $workflow,
    ): WorkflowContext {
        $execution = $this->repository->find($executionId);

        $context = new WorkflowContext();

        $stepExecutions = $this->repository->steps($execution);

        foreach ($stepExecutions as $stepExecution) {
            if ($stepExecution->isCompleted()) {
                continue;
            }

            $stepExecution->status = StepStatus::Running;
            $stepExecution->attempts++;
            $stepExecution->startedAt = new \DateTimeImmutable();
        
            $step = app($stepExecution->step);

            try {
                $output = $step->handle($context);

                $stepExecution->status = StepStatus::Completed;
                $stepExecution->output = $output;
                $stepExecution->completedAt = new \DateTimeImmutable();

                $this->repository->saveStep($stepExecution);
            } catch (\Throwable $exception) {
                $stepExecution->status = StepStatus::Failed;
                $stepExecution->error = $exception->getMessage();

                $this->repository->saveStep($stepExecution);

                $execution->status = ExecutionStatus::Failed;
                $execution->failedAt = new \DateTimeImmutable();

                $this->repository->save($execution);

                throw $exception;
            }
        }

        $execution->status = ExecutionStatus::Completed;
        $execution->completedAt = new \DateTimeImmutable();

        $this->repository->save($execution);

        return $context;
    }
}