<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;

/**
 * Durable storage for workflow executions.
 *
 * Implementations must satisfy the guarantees documented in
 * docs/execution-lifecycle.md: atomic plan creation, compare-and-set
 * claiming, and transactional, claim-verified checkpoints.
 */
interface ExecutionRepository
{
    /**
     * Create an execution and its complete step plan atomically.
     *
     * Every step is recorded in `pending` state before anything runs, so
     * the persisted plan is a complete snapshot of the workflow definition
     * regardless of when or whether execution happens.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $steps  Ordered step class names.
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = []
    ): WorkflowExecution;

    /**
     * @throws ExecutionNotFoundException
     */
    public function find(
        int|string $id
    ): WorkflowExecution;

    /**
     * Atomically claim an execution for this worker (compare-and-set).
     *
     * Succeeds when the execution is `pending`, `failed`, or `running`
     * with a claim older than $staleAfterSeconds. Exactly one concurrent
     * claimant can succeed.
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotRunnableException when the execution is completed.
     * @throws ExecutionAlreadyClaimedException when another worker holds a fresh claim.
     */
    public function claim(
        int|string $id,
        string $claimToken,
        int $staleAfterSeconds
    ): WorkflowExecution;

    /**
     * @return StepExecution[] ordered by position.
     */
    public function steps(
        WorkflowExecution $execution
    ): array;

    public function saveStep(
        StepExecution $step
    ): void;

    /**
     * Persist a completed step together with the execution's context
     * snapshot in one transaction, verifying this worker still holds the
     * claim. On claim loss nothing is written.
     *
     * @throws ClaimLostException
     */
    public function checkpoint(
        WorkflowExecution $execution,
        StepExecution $step
    ): void;

    /**
     * Persist a terminal transition (completed or failed) and release the
     * claim, verifying this worker still holds it.
     *
     * @throws ClaimLostException
     */
    public function release(
        WorkflowExecution $execution
    ): void;
}
