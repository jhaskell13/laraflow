<?php

declare(strict_types=1);

namespace Laraflow\Support;

use DateTimeInterface;
use Laraflow\Workflow\IdempotencyRecord;
use Laraflow\Workflow\RecoveryCandidate;
use Laraflow\Workflow\Scheduling\ScheduleAction;
use Laraflow\Workflow\Scheduling\ScheduledWorkflow;
use Laraflow\Workflow\Scheduling\ScheduleTick;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @internal The one place a Laraflow record becomes JSON for a machine
 * — every `--json` command serialises through here, so two commands
 * cannot spell the same field two ways. Instants are ISO 8601 with an
 * offset; enums are their backing strings; nothing here reaches past
 * the public read handles.
 *
 * Remembered work is serialised without its value, for the reason the
 * console never prints it: it is the result of a side effect and may
 * carry anything.
 */
final class JsonReport
{
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }

    /**
     * @param  bool  $detailed  Include input and context — the payloads —
     *                          which the show command prints and a listing does not.
     * @return array<string, mixed>
     */
    public static function execution(WorkflowExecution $execution, bool $detailed = false): array
    {
        $record = [
            'id' => $execution->id,
            'workflow' => $execution->workflow,
            'status' => $execution->status->value,
            'plan_version' => $execution->planVersion,
            'correlation_id' => $execution->correlationId,
            'tenant_id' => $execution->tenantId,
            'parent_execution_id' => $execution->parentExecutionId,
            'parent_step_execution_id' => $execution->parentStepExecutionId,
            'error' => $execution->error,
            'error_class' => $execution->errorClass,
            'created_at' => self::date($execution->createdAt),
            'started_at' => self::date($execution->startedAt),
            'finished_at' => self::date($execution->finishedAt),
            'completed_at' => self::date($execution->completedAt),
            'failed_at' => self::date($execution->failedAt),
            'cancelled_at' => self::date($execution->cancelledAt),
            'cancel_requested_at' => self::date($execution->cancelRequestedAt),
            'cancel_reason' => $execution->cancelReason,
            'next_retry_at' => self::date($execution->nextRetryAt),
            'dispatched_at' => self::date($execution->dispatchedAt),
            'available_at' => self::date($execution->availableAt),
            'claimed' => $execution->claimToken !== null,
            'claimed_at' => self::date($execution->claimedAt),
            'duration_ms' => $execution->durationMs(),
            'fruitless_claims' => $execution->fruitlessClaims,
            'deferred_until' => self::date($execution->deferredUntil),
            'deferrals' => $execution->deferrals,
            'payloads_scrubbed_at' => self::date($execution->payloadsScrubbedAt),
        ];

        if ($detailed) {
            $record['input'] = (object) $execution->input;
            $record['context'] = (object) $execution->context;
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public static function step(StepExecution $step): array
    {
        return [
            'id' => $step->id,
            'position' => $step->position,
            'step' => $step->step,
            'status' => $step->status->value,
            'attempts' => $step->attempts,
            'compensates_step_execution_id' => $step->compensatesStepExecutionId,
            'wait_signal' => $step->waitSignal,
            'output' => $step->output,
            'error' => $step->error,
            'error_class' => $step->errorClass,
            'started_at' => self::date($step->startedAt),
            'completed_at' => self::date($step->completedAt),
            'failed_at' => self::date($step->failedAt),
            'timeout_at' => self::date($step->timeoutAt),
            'next_retry_at' => self::date($step->nextRetryAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attempt(StepAttempt $attempt): array
    {
        return [
            'step_execution_id' => $attempt->stepExecutionId,
            'attempt' => $attempt->attempt,
            'status' => $attempt->status->value,
            'error' => $attempt->error,
            'error_class' => $attempt->errorClass,
            'started_at' => self::date($attempt->startedAt),
            'ended_at' => self::date($attempt->endedAt),
            'duration_ms' => $attempt->durationMs(),
            'next_retry_at' => self::date($attempt->nextRetryAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function remembered(IdempotencyRecord $record): array
    {
        return [
            'step_position' => $record->stepPosition,
            'key' => $record->key,
            'status' => $record->status->value,
            'error_class' => $record->errorClass,
            'started_at' => self::date($record->startedAt),
            'settled_at' => self::date($record->settledAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function candidate(RecoveryCandidate $candidate): array
    {
        return [
            'execution' => $candidate->execution->id,
            'workflow' => $candidate->execution->workflow,
            'status' => $candidate->execution->status->value,
            'reason' => $candidate->reason->value,
            'attempts' => $candidate->attempts,
            'fruitless_claims' => $candidate->fruitlessClaims,
            'abandoned' => $candidate->abandoned,
            'workflow_missing' => $candidate->workflowMissing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schedule(ScheduledWorkflow $schedule): array
    {
        return [
            'workflow' => $schedule->workflow,
            'cron' => $schedule->cron,
            'timezone' => $schedule->timezone,
            'missed_tick_policy' => $schedule->missedTickPolicy->value,
            'without_overlapping' => $schedule->withoutOverlapping,
            'tenant_id' => $schedule->tenantId,
            'last_tick_at' => self::date($schedule->lastTickAt),
            'paused_at' => self::date($schedule->pausedAt),
            'declared_at' => self::date($schedule->declaredAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tick(ScheduleTick $tick): array
    {
        return [
            'tick_at' => self::date($tick->tickAt),
            'outcome' => $tick->outcome->value,
            'execution' => $tick->workflowExecutionId,
            'missed' => $tick->missed,
            'missed_from' => self::date($tick->missedFrom),
            'overlapped_execution_id' => $tick->overlappedExecutionId,
            'claimed_at' => self::date($tick->createdAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scheduleAction(ScheduleAction $action): array
    {
        return [
            'workflow' => $action->workflow,
            'outcome' => $action->outcome,
            'tick_at' => self::date($action->tickAt),
            'execution' => $action->executionId,
            'missed' => $action->missed,
            'overlapped_execution_id' => $action->overlappedExecutionId,
            'delivery' => $action->delivery,
            'detail' => $action->detail,
        ];
    }
}
