<?php

namespace Laraflow\Workflow;

interface ExecutionRepository
{
    public function create(
        string $workflow,
        array $input = []
    ): WorkflowExecution;

    public function find(
        int|string $id
    ): WorkflowExecution;

    public function save(
        WorkflowExecution $execution
    ): void;

    public function createStep(
        WorkflowExecution $execution,
        string $step,
        int $position,
        mixed $input = null
    ): StepExecution;

    public function steps(
        WorkflowExecution $execution
    ): array;

    public function saveStep(
        StepExecution $step
    ): void;
}