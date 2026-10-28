<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Query\Expression;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Support\Clock;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Clock\ClockInterface;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class EloquentExecutionRepository implements ExecutionRepository
{
    // The contract is deliberately one interface — see
    // docs/public-api.md — but its 22 methods answer four different
    // questions. Grouping the implementation by question keeps each
    // group's shared invariants next to the methods that rely on them.
    use Concerns\AppliesRetention;
    use Concerns\QueriesExecutions;
    use Concerns\ScansForRecovery;

    protected ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new Clock;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $steps
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = [],
        ?ExecutionOptions $options = null
    ): WorkflowExecution {
        $model = $this->transaction(function () use ($workflow, $input, $steps, $options) {
            $model = WorkflowExecutionModel::query()->create([
                'workflow' => $workflow,
                'status' => ExecutionStatus::Pending->value,
                'input' => $input,
                'context' => [],
                'correlation_id' => $options?->correlationId,
                'tenant_id' => $options?->tenantId,
                'parent_execution_id' => $options?->parentExecutionId,
                'parent_step_execution_id' => $options?->parentStepExecutionId,
                // Written rather than left to the column default, so the
                // handle built from this model reads the same number a
                // reload would.
                'fruitless_claims' => 0,
            ]);

            foreach ($steps as $position => $stepClass) {
                $model->steps()->create([
                    'step' => $stepClass,
                    'position' => $position,
                    'status' => StepStatus::Pending->value,
                    'attempts' => 0,
                ]);
            }

            return $model;
        });

        return $this->toDomain($model);
    }

    public function find(
        int|string $id
    ): WorkflowExecution {
        $model = WorkflowExecutionModel::query()->find($id);

        if ($model === null) {
            throw ExecutionNotFoundException::forId($id);
        }

        return $this->toDomain($model);
    }

    public function claim(
        int|string $id,
        string $claimToken,
        int $staleAfterSeconds,
        bool $ignoreRetrySchedule = false
    ): WorkflowExecution {
        $now = $this->clock->now();
        $staleBefore = $now->modify("-{$staleAfterSeconds} seconds");

        // Read, not returned by the UPDATE: an engine-portable UPDATE
        // cannot hand back the previous row. A status that moves between
        // this read and the claim is decided by the claim's own
        // predicate; the read only informs the runner's choice between
        // "re-executing a failure" and "resuming after a retry or wait".
        $resumedFrom = WorkflowExecutionModel::query()->whereKey($id)->value('status');

        $claimed = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where(function ($query) use ($staleBefore, $now, $ignoreRetrySchedule) {
                $query
                    ->whereIn('status', [
                        ExecutionStatus::Pending->value,
                        ExecutionStatus::Failed->value,
                    ])
                    ->orWhere(function ($query) use ($now, $ignoreRetrySchedule) {
                        $query->where('status', ExecutionStatus::Retrying->value);

                        if (! $ignoreRetrySchedule) {
                            // Claimable only once its backoff has elapsed;
                            // early claiming is the deliberate operator
                            // override. A null due time (which the runner
                            // never writes) counts as due, because a
                            // schedule nobody can read must not make an
                            // execution unclaimable forever.
                            $query->where(function ($query) use ($now) {
                                $query
                                    ->whereNull('next_retry_at')
                                    ->orWhere('next_retry_at', '<=', $now);
                            });
                        }
                    })
                    ->orWhere(function ($query) use ($now, $ignoreRetrySchedule) {
                        $query->where('status', ExecutionStatus::Waiting->value);

                        if (! $ignoreRetrySchedule) {
                            // Unlike retrying, a null instant here means
                            // an indefinite signal wait: claimable only
                            // once a signal sets next_retry_at, never by
                            // the clock.
                            $query
                                ->whereNotNull('next_retry_at')
                                ->where('next_retry_at', '<=', $now);
                        }
                    })
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query
                            ->where('status', ExecutionStatus::Running->value)
                            ->where(function ($query) use ($staleBefore) {
                                $query
                                    ->whereNull('claimed_at')
                                    ->orWhere('claimed_at', '<=', $staleBefore);
                            });
                    });
            })
            ->update($this->counted([
                'status' => ExecutionStatus::Running->value,
                'claim_token' => $claimToken,
                'claimed_at' => $now,
                // Claiming means this is running now, so any previously
                // scheduled retry is spent. Mirrors the same invariant
                // in WorkflowExecution::markRunning(); without it a
                // running row would keep advertising a past due time.
                'next_retry_at' => null,
                // Mirrors markRunning(): a re-executed failure is not
                // finished, and must not look eligible to retention
                // while a worker is actively running it. The recorded
                // duration goes with it — it described a run that this
                // claim is superseding.
                'finished_at' => null,
                'duration_ms' => null,
                // One more claim that has, so far, reached no step. The
                // first step write under this claim zeroes it; a worker
                // that never gets that far leaves it standing, and
                // recovery abandons the execution once it reaches the
                // bound instead of re-dispatching it forever.
                'fruitless_claims' => new Expression('fruitless_claims + 1'),
            ]));

        if ($claimed === 0) {
            $this->explainFailedClaim($id);
        }

        // Record the first start without disturbing the original timestamp
        // on re-execution; guarded by the claim we just took.
        WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where('claim_token', $claimToken)
            ->whereNull('started_at')
            ->update($this->counted(['started_at' => $now]));

        $execution = $this->find($id);
        $execution->resumedFrom = is_string($resumedFrom) ? ExecutionStatus::tryFrom($resumedFrom) : null;

        return $execution;
    }

    public function steps(
        WorkflowExecution $execution
    ): array {
        return StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->orderBy('position')
            ->get()
            ->map(fn (StepExecutionModel $model) => $this->stepToDomain($model))
            ->all();
    }

    public function saveStep(
        WorkflowExecution $execution,
        StepExecution $step,
        ?StepAttempt $attempt = null
    ): void {
        // The step row has no claim column of its own; the guard is the
        // executions row, touched in the same transaction. A step write
        // costs one guarded UPDATE more than a bare row write, and the
        // step row can no longer be scribbled by a worker that has lost
        // the claim. The same UPDATE records that this claim made
        // progress: a step row written under it means the execution is
        // past the claim-to-first-step window recovery bounds separately.
        $this->transaction(function () use ($execution, $step, $attempt) {
            $this->writeStep($step);

            if ($attempt !== null) {
                $this->appendAttempt($attempt);
            }

            $this->assertClaimHeld($execution, ['fruitless_claims' => 0]);
        });
    }

    public function checkpoint(
        WorkflowExecution $execution,
        StepExecution $step,
        ?StepAttempt $attempt = null
    ): void {
        $this->transaction(function () use ($execution, $step, $attempt) {
            $this->writeStep($step);

            if ($attempt !== null) {
                $this->appendAttempt($attempt);
            }

            $this->assertClaimHeld($execution, [
                'context' => $this->asJson($execution->context),
                'fruitless_claims' => 0,
            ]);
        });
    }

    public function recordSignal(
        int|string $id,
        string $signal,
        array $payload,
        DateTimeImmutable $at
    ): WorkflowExecution {
        $this->transaction(function () use ($id, $signal, $payload, $at) {
            // Whether anything is parked on this name is decided by a
            // read, not by the payload UPDATE's affected-row count: a
            // duplicate delivery rewriting an identical payload counts
            // zero rows *changed* on MySQL and MariaDB and would read
            // as a refusal. A duplicate before the resume overwrites
            // the payload instead — last delivery wins, mirroring the
            // at-least-once sources signals come from.
            //
            // The row is locked for the transaction (a no-op on SQLite,
            // whose writers serialise anyway), so a worker completing
            // this step and parking the next cannot slip between this
            // read and the write below and have a late duplicate land
            // on a completed step.
            $row = StepExecutionModel::query()
                ->where('workflow_execution_id', $id)
                ->where('status', StepStatus::Waiting->value)
                ->where('wait_signal', $signal)
                ->lockForUpdate()
                ->first(['id', 'wait_signal']);

            // The database match is case-insensitive on MySQL, MariaDB,
            // and SQL Server; the reserved prefix is an authorization
            // boundary, so the name must match byte for byte.
            if ($row === null || $row->wait_signal !== $signal) {
                throw SignalRefusedException::forSignal($id, $signal);
            }

            StepExecutionModel::query()
                ->whereKey($row->id)
                ->where('status', StepStatus::Waiting->value)
                ->update(['output' => $this->asJson($payload)]);

            // Compare-and-set on the executions row, counted, so a
            // worker claiming on a concurrent timeout rolls the payload
            // back with the refusal.
            $due = WorkflowExecutionModel::query()
                ->whereKey($id)
                ->where('status', ExecutionStatus::Waiting->value)
                ->update($this->counted(['next_retry_at' => $at]));

            if ($due !== 1) {
                throw SignalRefusedException::forSignal($id, $signal);
            }
        });

        return $this->find($id);
    }

    public function recordDispatch(
        int|string $id,
        DateTimeImmutable $dispatchedAt,
        ?DateTimeImmutable $availableAt = null
    ): void {
        $updated = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->update($this->counted([
                'dispatched_at' => $dispatchedAt,
                // A delivery cannot be available before it was dispatched;
                // a caller passing an earlier instant meant "now".
                'available_at' => $availableAt !== null && $availableAt > $dispatchedAt
                    ? $availableAt
                    : $dispatchedAt,
            ]));

        if ($updated === 0) {
            throw ExecutionNotFoundException::forId($id);
        }
    }

    public function requestCancellation(
        int|string $id,
        ?string $reason,
        DateTimeImmutable $at,
        ?DateTimeImmutable $staleBefore = null
    ): WorkflowExecution {
        // Unclaimed work is cancelled outright. The status predicate is
        // the same compare-and-set claiming uses, so this cannot race a
        // worker: whichever UPDATE lands first, the other is refused. A
        // running execution whose claim is older than $staleBefore is
        // unclaimed by contract too — the same test claim() applies —
        // which is what lets an operator cancel an abandoned execution
        // no worker will ever return to.
        $cancelled = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where(function ($query) use ($staleBefore) {
                $query->whereIn('status', [
                    ExecutionStatus::Pending->value,
                    ExecutionStatus::Failed->value,
                    ExecutionStatus::Retrying->value,
                    // A waiting execution is unclaimed, so cancellation
                    // takes the immediate path; whoever signals it
                    // afterwards is refused by the status check.
                    ExecutionStatus::Waiting->value,
                ]);

                if ($staleBefore !== null) {
                    $query->orWhere(function ($query) use ($staleBefore) {
                        $query
                            ->where('status', ExecutionStatus::Running->value)
                            ->where(function ($query) use ($staleBefore) {
                                $query
                                    ->whereNull('claimed_at')
                                    ->orWhere('claimed_at', '<=', $staleBefore);
                            });
                    });
                }
            })
            ->update($this->counted([
                'status' => ExecutionStatus::Cancelled->value,
                'cancelled_at' => $at,
                'cancel_requested_at' => $at,
                'cancel_reason' => $reason,
                // Cancellation is terminal, so it finishes the execution
                // — the same write markCancelled() makes on the handle.
                'finished_at' => $at,
                // A cancelled execution must never surface in the scan.
                'next_retry_at' => null,
                'claim_token' => null,
                'claimed_at' => null,
            ]));

        if ($cancelled === 1) {
            return $this->find($id);
        }

        // A live worker owns it: leave a request for its next step
        // boundary rather than rewriting state underneath it.
        $requested = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where('status', ExecutionStatus::Running->value)
            ->update($this->counted([
                'cancel_requested_at' => $at,
                'cancel_reason' => $reason,
            ]));

        if ($requested === 1) {
            return $this->find($id);
        }

        $current = $this->find($id);

        if ($current->isCancelled()) {
            return $current;
        }

        throw ExecutionNotCancellableException::forStatus($id, $current->status);
    }

    public function release(
        WorkflowExecution $execution
    ): void {
        $values = [
            'status' => $execution->status->value,
            // An orderly release means the claim concluded deliberately —
            // a park, a scheduled retry, a terminal state. The fruitless
            // counter exists for claims that die before any orderly
            // write, so a heartbeat that claims, consults a child, and
            // parks again must not be counted toward abandonment.
            'fruitless_claims' => 0,
            'context' => $this->asJson($execution->context),
            'error' => $execution->error,
            'error_class' => $execution->errorClass,
            'completed_at' => $execution->completedAt,
            'failed_at' => $execution->failedAt,
            'finished_at' => $execution->finishedAt,
            'duration_ms' => $execution->durationMs(),
            'next_retry_at' => $execution->nextRetryAt,
            'claim_token' => null,
            'claimed_at' => null,
        ];

        // Cancellation columns are written only when this worker's handle
        // carries them. The handle was hydrated at claim time; an operator
        // may have called cancel() since, and requestCancellation() wrote
        // cancel_requested_at behind this worker's back. Releasing the
        // handle's stale nulls would erase that request — and the contract
        // says nothing clears it until a worker acts on it.
        if ($execution->cancelledAt !== null) {
            $values['cancelled_at'] = $execution->cancelledAt;
            $values['cancel_reason'] = $execution->cancelReason;
        }

        if ($execution->cancelRequestedAt !== null) {
            $values['cancel_requested_at'] = $execution->cancelRequestedAt;
        }

        $updated = WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->where('claim_token', $execution->claimToken)
            ->update($this->counted($values));

        if ($updated === 0) {
            throw ClaimLostException::forId($execution->id);
        }
    }

    /**
     * Write $values to the executions row only if this worker still
     * holds the claim, and throw otherwise. Called inside the step
     * transactions so a lost claim rolls back the step write and the
     * attempt row with it: a worker without the claim must not persist
     * anything.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ClaimLostException
     */
    protected function assertClaimHeld(WorkflowExecution $execution, array $values): void
    {
        $updated = WorkflowExecutionModel::query()
            ->whereKey($execution->id)
            ->where('claim_token', $execution->claimToken)
            ->update($this->counted($values));

        if ($updated === 0) {
            throw ClaimLostException::forId($execution->id);
        }
    }

    protected function writeStep(StepExecution $step): void
    {
        StepExecutionModel::query()
            ->whereKey($step->id)
            ->update([
                'status' => $step->status->value,
                'attempts' => $step->attempts,
                'output' => $this->asJson($step->output),
                'error' => $step->error,
                'error_class' => $step->errorClass,
                'started_at' => $step->startedAt,
                'completed_at' => $step->completedAt,
                'failed_at' => $step->failedAt,
                'timeout_at' => $step->timeoutAt,
                'next_retry_at' => $step->nextRetryAt,
                'wait_signal' => $step->waitSignal,
            ]);
    }

    protected function appendAttempt(StepAttempt $attempt): void
    {
        StepAttemptModel::query()->create([
            'workflow_execution_id' => $attempt->executionId,
            'step_execution_id' => $attempt->stepExecutionId,
            'attempt' => $attempt->attempt,
            'status' => $attempt->status->value,
            'error' => $attempt->error,
            'error_class' => $attempt->errorClass,
            'started_at' => $attempt->startedAt,
            'ended_at' => $attempt->endedAt,
            'duration_ms' => $attempt->durationMs(),
            'next_retry_at' => $attempt->nextRetryAt,
        ]);
    }

    protected function explainFailedClaim(int|string $id): never
    {
        $current = WorkflowExecutionModel::query()->find($id);

        if ($current === null) {
            throw ExecutionNotFoundException::forId($id);
        }

        $status = ExecutionStatus::from($current->status);

        if ($status === ExecutionStatus::Running) {
            throw ExecutionAlreadyClaimedException::forId($id);
        }

        if ($status === ExecutionStatus::Retrying) {
            // The only way a retrying claim misses: it is not due yet.
            throw RetryNotDueException::forId($id, $current->next_retry_at?->toImmutable());
        }

        if ($status === ExecutionStatus::Waiting) {
            throw ExecutionStillWaitingException::forId($id, $current->next_retry_at?->toImmutable());
        }

        throw ExecutionNotRunnableException::forStatus($id, $status);
    }

    protected function attemptToDomain(StepAttemptModel $model): StepAttempt
    {
        return new StepAttempt(
            executionId: $model->workflow_execution_id,
            stepExecutionId: $model->step_execution_id,
            attempt: $model->attempt,
            status: StepAttemptStatus::from($model->status),
            error: $model->error,
            errorClass: $model->error_class,
            startedAt: $model->started_at?->toImmutable(),
            endedAt: $model->ended_at?->toImmutable(),
            nextRetryAt: $model->next_retry_at?->toImmutable(),
        );
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function transaction(Closure $callback): mixed
    {
        return WorkflowExecutionModel::query()
            ->getConnection()
            ->transaction($callback);
    }

    /**
     * Values for an executions-table update whose affected-row count
     * must mean "a row matched the WHERE". MySQL and MariaDB report
     * rows *changed*, not rows matched, so rewriting identical values —
     * a duplicate dispatch in the same second, a checkpoint whose
     * context did not move — would count zero and read as a lost claim
     * or a missing row. Bumping the version column on every write makes
     * the two counts the same thing on every engine.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function counted(array $values): array
    {
        return $values + ['version' => new Expression('version + 1')];
    }

    /**
     * A JSON column value for a query-builder update. Eloquent casts do
     * not apply to Builder::update(), and only some grammars encode an
     * array themselves — SQL Server's binds its elements one by one and
     * the write silently misses. Encoding here keeps every engine on
     * the byte-identical write the model cast will read back.
     *
     * Any durable value: a step's output may be a scalar as well as an
     * array (DurableValue allows both), and null stays SQL NULL.
     */
    protected function asJson(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }

    protected function toDomain(
        WorkflowExecutionModel $model
    ): WorkflowExecution {
        return new WorkflowExecution(
            id: $model->id,
            workflow: $model->workflow,
            input: $model->input ?? [],
            status: ExecutionStatus::from($model->status),
            context: $model->context ?? [],
            error: $model->error,
            errorClass: $model->error_class,
            claimToken: $model->claim_token,
            claimedAt: $model->claimed_at?->toImmutable(),
            createdAt: $model->created_at?->toImmutable(),
            startedAt: $model->started_at?->toImmutable(),
            completedAt: $model->completed_at?->toImmutable(),
            failedAt: $model->failed_at?->toImmutable(),
            nextRetryAt: $model->next_retry_at?->toImmutable(),
            cancelRequestedAt: $model->cancel_requested_at?->toImmutable(),
            cancelledAt: $model->cancelled_at?->toImmutable(),
            cancelReason: $model->cancel_reason,
            correlationId: $model->correlation_id,
            tenantId: $model->tenant_id,
            parentExecutionId: $model->parent_execution_id,
            parentStepExecutionId: $model->parent_step_execution_id,
            dispatchedAt: $model->dispatched_at?->toImmutable(),
            availableAt: $model->available_at?->toImmutable(),
            finishedAt: $model->finished_at?->toImmutable(),
            payloadsScrubbedAt: $model->payloads_scrubbed_at?->toImmutable(),
            fruitlessClaims: $model->fruitless_claims,
            persistedDurationMs: $model->duration_ms,
        );
    }

    protected function stepToDomain(
        StepExecutionModel $model
    ): StepExecution {
        return new StepExecution(
            id: $model->id,
            executionId: $model->workflow_execution_id,
            step: $model->step,
            position: $model->position,
            status: StepStatus::from($model->status),
            attempts: $model->attempts,
            input: $model->input,
            output: $model->output,
            error: $model->error,
            errorClass: $model->error_class,
            startedAt: $model->started_at?->toImmutable(),
            completedAt: $model->completed_at?->toImmutable(),
            failedAt: $model->failed_at?->toImmutable(),
            timeoutAt: $model->timeout_at?->toImmutable(),
            nextRetryAt: $model->next_retry_at?->toImmutable(),
            waitSignal: $model->wait_signal,
        );
    }
}
