<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\StepPolicy;

abstract class Workflow
{
    /**
     * The ordered list of step classes that make up this workflow.
     *
     * @return list<class-string<Step>>
     */
    abstract public function steps(): array;

    /**
     * Which version of this workflow's step plan the code describes.
     *
     * Recorded on every execution this workflow creates. Bump it when
     * steps() changes and declare the matching entry in
     * planMigrations(), and in-flight executions are carried forward
     * the next time they are claimed. Leave it alone and a changed
     * steps() strands them with WorkflowDefinitionMismatchException,
     * exactly as before versioning existed — the refusal is the
     * default and silence means refuse.
     *
     * See docs/workflow-versioning.md.
     */
    public function version(): int
    {
        return 1;
    }

    /**
     * How to carry a persisted plan forward, keyed by the version each
     * entry migrates *from*.
     *
     * Each value is a callable taking the PlanDraft and changing it in
     * place; an execution several versions behind runs them in turn,
     * one hop at a time. A migration may only change the part of the
     * plan the execution has not reached — see PlanDraft.
     *
     *     public function planMigrations(): array
     *     {
     *         return [
     *             1 => fn (PlanDraft $plan) => $plan->insertAfter(ChargePayment::class, RecordTax::class),
     *         ];
     *     }
     *
     * @return array<int, callable(PlanDraft): mixed>
     */
    public function planMigrations(): array
    {
        return [];
    }

    /**
     * The cadence this workflow runs on — or null, the default: not
     * scheduled.
     *
     * A declaration, not an activation: Laraflow starts ticking it only
     * once the application lists this class under
     * `laraflow.schedules.workflows` (or a package registers it through
     * the ScheduleRegistry). The class is the schedule's identity — a
     * workflow has at most one, and a second cadence for the same steps
     * is a subclass overriding only this method.
     *
     * See docs/schedules.md.
     */
    public function schedule(): ?Schedule
    {
        return null;
    }

    /**
     * Retry and timeout policy for this workflow's steps that do not
     * declare one of their own (see Policy\HasStepPolicy). Null falls
     * back to the configured package default, which disables retries.
     */
    public function defaultStepPolicy(): ?StepPolicy
    {
        return null;
    }

    /**
     * Whether the execution stops at a terminally failed step (the
     * default) or runs the remaining steps anyway. See FailurePolicy.
     */
    public function failurePolicy(): FailurePolicy
    {
        return FailurePolicy::Stop;
    }
}
