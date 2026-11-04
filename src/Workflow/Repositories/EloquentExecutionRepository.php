<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\ExecutionStillWaitingException;
use Laraflow\Exceptions\Workflow\RetryNotDueException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\TenantThrottledException;
use Laraflow\Support\Clock;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\SignalInboxModel;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepAttemptStatus;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\Tenancy\TenantCeiling;
use Laraflow\Workflow\Tenancy\TenantCeilings;
use Laraflow\Workflow\Tenancy\TenantLimits;
use Laraflow\Workflow\Wait;
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

    public function __construct(
        ClockInterface $clock,
        protected TenantCeilings $ceilings,
    ) {
        $this->clock = $clock;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $steps
     */
    public function create(
        string $workflow,
        array $input = [],
        array $steps = [],
        ?ExecutionOptions $options = null,
        int $planVersion = 1
    ): WorkflowExecution {
        $model = $this->transaction(function () use ($workflow, $input, $steps, $options, $planVersion) {
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
                'deferrals' => 0,
                'plan_version' => $planVersion,
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
        int $id
    ): WorkflowExecution {
        $model = WorkflowExecutionModel::query()->find($id);

        if ($model === null) {
            throw ExecutionNotFoundException::forId($id);
        }

        return $this->toDomain($model);
    }

    public function claim(
        int $id,
        string $claimToken,
        int $staleAfterSeconds,
        bool $ignoreRetrySchedule = false,
        bool $reexecuteFailed = false
    ): WorkflowExecution {
        $now = $this->clock->now();
        $staleBefore = $now->modify("-{$staleAfterSeconds} seconds");

        // Read, not returned by the UPDATE: an engine-portable UPDATE
        // cannot hand back the previous row. A status that moves between
        // this read and the claim is decided by the claim's own
        // predicate; the read only informs the runner's choice between
        // "re-executing a failure" and "resuming after a retry or wait"
        // — and names the tenant the ceilings are consulted for.
        $before = WorkflowExecutionModel::query()->whereKey($id)->first(['id', 'status', 'tenant_id', 'workflow', 'correlation_id', 'next_retry_at', 'claimed_at']);
        $resumedFrom = $before?->status;

        // The ceilings, before the compare-and-set and nowhere else:
        // every path to a run passes here (docs/tenancy.md). Only for a
        // row this claim could actually take: a duplicate delivery for
        // a running, finished, or not-yet-due execution must be refused
        // as exactly that, not deferred — deferring it would stamp the
        // record and have the queue job re-push a claim that can never
        // land (the Milestone 10 audit's second finding). An execution
        // with no tenant has nothing to be bounded by.
        $limits = $before === null || $before->tenant_id === null || ! $this->claimableNow($before, $now, $staleBefore, $ignoreRetrySchedule, $reexecuteFailed)
            ? TenantLimits::none()
            : $this->assertWithinTenantCeilings($before, $now, $staleBefore);

        $claimed = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->where(function ($query) use ($staleBefore, $now, $ignoreRetrySchedule, $reexecuteFailed) {
                // Failed is terminal until an operator says otherwise.
                // Accepting it unconditionally let a duplicate queue
                // delivery — the at-least-once kind every broker is
                // allowed — re-run a terminally failed execution, its
                // side effects included, with nobody having asked
                // (Milestone 10 audit). Re-execution is an explicit
                // opt-in, carried from the operator's action to here.
                $query
                    ->whereIn('status', $reexecuteFailed
                        ? [ExecutionStatus::Pending->value, ExecutionStatus::Failed->value]
                        : [ExecutionStatus::Pending->value])
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
                        // Compensating is claimed work like running is:
                        // the pass runs under a claim, and a worker that
                        // dies mid-undo leaves the same stale mark. The
                        // stale *scan* has always returned these rows;
                        // without them here, recovery found them,
                        // dispatched them, and the claim refused them —
                        // forever (Milestone 9 audit).
                        $query
                            ->whereIn('status', [
                                ExecutionStatus::Running->value,
                                ExecutionStatus::Compensating->value,
                            ])
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
                // A claim that lands ends any deferral: the record must
                // not say "waiting for a tenant slot" about a run that
                // has one.
                'deferred_until' => null,
                'deferrals' => 0,
            ]));

        if ($claimed === 0) {
            $this->explainFailedClaim($id);
        }

        if ($limits->maxClaimsPerMinute !== null && $before?->tenant_id !== null) {
            // Counted after the claim landed, so a refused claim is not
            // a claim; only configured ceilings touch the cache.
            $this->ceilings->recordClaim($before->tenant_id);
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

    public function createMember(
        int $parentStepExecutionId,
        int $index,
        Closure $create
    ): mixed {
        return $this->transaction(function () use ($parentStepExecutionId, $index, $create): mixed {
            // The parent step's row is the lock both creators queue on:
            // a worker resuming a partial creation and a stale-reclaimed
            // one still running it each counted k members and each made
            // member k (Milestone 10 audit). Under the lock, the second
            // counts k + 1 and makes nothing. On SQLite the lock is a
            // no-op and the single writer does the ordering: the loser
            // of that race gets "database is locked", not a duplicate.
            StepExecutionModel::query()
                ->whereKey($parentStepExecutionId)
                ->lockForUpdate()
                ->first(['id']);

            $existing = WorkflowExecutionModel::query()
                ->where('parent_step_execution_id', $parentStepExecutionId)
                ->count();

            if ($existing > $index) {
                return null;
            }

            return $create();
        });
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

    public function appendCompensation(
        WorkflowExecution $execution,
        StepExecution $compensates
    ): StepExecution {
        return $this->transaction(function () use ($execution, $compensates): StepExecution {
            $next = (int) StepExecutionModel::query()
                ->where('workflow_execution_id', $execution->id)
                ->max('position');

            $model = StepExecutionModel::query()->create([
                'workflow_execution_id' => $execution->id,
                'step' => $compensates->step,
                'position' => $next + 1,
                'compensates_step_execution_id' => $compensates->id,
                'status' => StepStatus::Pending->value,
                'attempts' => 0,
            ]);

            $this->assertClaimHeld($execution, ['fruitless_claims' => 0]);

            return $this->stepToDomain($model);
        });
    }

    public function checkpointStatus(
        WorkflowExecution $execution
    ): void {
        $this->transaction(function () use ($execution) {
            $this->assertClaimHeld($execution, [
                'status' => $execution->status->value,
                'fruitless_claims' => 0,
            ]);
        });
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

    public function rewritePlan(
        WorkflowExecution $execution,
        int $frozenBefore,
        array $tail,
        int $planVersion
    ): array {
        $this->transaction(function () use ($execution, $frozenBefore, $tail, $planVersion) {
            StepExecutionModel::query()
                ->where('workflow_execution_id', $execution->id)
                ->where('position', '>=', $frozenBefore)
                ->delete();

            foreach ($tail as $offset => $stepClass) {
                StepExecutionModel::query()->create([
                    'workflow_execution_id' => $execution->id,
                    'step' => $stepClass,
                    'position' => $frozenBefore + $offset,
                    'status' => StepStatus::Pending->value,
                    'attempts' => 0,
                ]);
            }

            // Guarded last, like every other durable write: a worker
            // that lost the claim rolls the whole rewrite back. The
            // fruitless-claims counter is deliberately *not* cleared —
            // migrating is not reaching a step, and an execution whose
            // migration faults before its first step must stay bounded
            // by that counter.
            $this->assertClaimHeld($execution, ['plan_version' => $planVersion]);
        });

        $execution->planVersion = $planVersion;

        return $this->steps($execution);
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
        int $id,
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
            // The lock is what serialises this against a worker
            // completing the step and parking the next, on the engines
            // that take it. SQLite does not: lockForUpdate() compiles
            // to nothing there, and no lock is taken until the first
            // write. What keeps SQLite correct is the compare-and-set in
            // each UPDATE below (status = waiting, on the step and on
            // the execution) — a race lost there is a refusal, rolled
            // back — and SQLite's single writer, which turns a
            // read-then-write race between two connections into a
            // "database is locked" error for one of them rather than a
            // lost or doubled write. The error is the caller's to retry.
            $row = StepExecutionModel::query()
                ->where('workflow_execution_id', $id)
                ->where('status', StepStatus::Waiting->value)
                ->where('wait_signal', $signal)
                ->lockForUpdate()
                ->first(['id', 'wait_signal']);

            $this->deliverSignal($id, $signal, $payload, $at, $row);
        });

        return $this->find($id);
    }

    public function park(
        WorkflowExecution $execution,
        StepExecution $step,
        ?DateTimeImmutable $nextEventAt
    ): ?array {
        return $this->transaction(function () use ($execution, $step, $nextEventAt): ?array {
            // One transaction, where there were two: the step row went
            // `waiting` and committed, and only then did the execution
            // park. A signal landing between them found the step parked
            // and the execution still `running`, and was refused — the
            // compare-and-set below it requires `waiting` (Milestone 10
            // audit). Now nothing outside sees either until both hold.
            //
            // Lock order is step, then execution, then inbox — the order
            // recordSignal() takes them in — so the two never deadlock.
            $this->writeStep($step);

            // The claim guard, and the execution-row lock that orders
            // this park against a recordSignal() buffering the very name
            // it waits on: whichever takes the row first, the other sees
            // its work — the signal finds the step waiting, or the park
            // finds the signal in the inbox.
            $this->assertClaimHeld($execution, [
                'context' => $this->asJson($execution->context),
                'fruitless_claims' => 0,
            ]);

            $buffered = $this->takeBufferedSignal($execution->id, $step);

            if ($buffered !== null) {
                // Answered before it parked: the payload lands where a
                // signal on a waiting step lands, and the execution
                // stays running under this claim for the caller to
                // resume. A worker that dies before it does leaves a
                // waiting, signalled step a stale reclaim resumes.
                StepExecutionModel::query()
                    ->whereKey($step->id)
                    ->update(['output' => $this->asJson($buffered)]);

                $step->output = $buffered;

                return $buffered;
            }

            $execution->markWaiting($nextEventAt);
            $this->release($execution);

            return null;
        });
    }

    public function recordDispatch(
        int $id,
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
        int $id,
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
                            // Compensating is claimed work, and an
                            // abandoned pass must be cancellable by an
                            // operator like an abandoned run is.
                            ->whereIn('status', [
                                ExecutionStatus::Running->value,
                                ExecutionStatus::Compensating->value,
                            ])
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
            ->whereIn('status', [ExecutionStatus::Running->value, ExecutionStatus::Compensating->value])
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

    public function deferred(ExecutionFilter $filter, DateTimeImmutable $now, int $limit = 100): array
    {
        return array_values($this->filtered($filter)
            ->where('deferred_until', '>', $now)
            // Terminal rows cannot be deferred, but a deferred pending
            // execution an operator then cancelled still carries its
            // stamp; what this answers is "what is a ceiling holding",
            // and nothing holds finished work.
            ->whereNotIn('status', [ExecutionStatus::Completed->value, ExecutionStatus::Cancelled->value])
            ->orderBy('deferred_until')
            ->limit(min(max(1, $limit), self::MAX_PAGE_SIZE))
            ->get()
            ->map(fn (WorkflowExecutionModel $model): WorkflowExecution => $this->toDomain($model))
            ->all());
    }

    /**
     * Put a signal on the step parked on it, or keep it in the inbox for
     * the park that will ask for it (docs/advanced-workflows.md).
     *
     * Decided under the execution row's lock, which park() takes in its
     * claim guard before it reads the inbox, so a park and this write
     * are ordered one way or the other — never interleaved: the signal
     * finds the step waiting, or the park finds the signal. The lock is
     * taken after the step row's (the caller's read), the order every
     * step checkpoint and park() use, so none of them deadlock.
     *
     * - `waiting`, on this name: the payload becomes the step's output
     *   and the execution is due now.
     * - unfinished otherwise — pending, running (a claim holds it, even
     *   one about to re-park this very wait), retrying, or waiting on
     *   another name: buffered, latest delivery wins.
     * - completed, cancelled, failed, compensating, or gone: refused —
     *   no park can follow. A reserved (`laraflow.`) name is refused
     *   unless it is exactly what a step is parked on: the machinery's
     *   signals have heartbeats that heal a lost one, and buffering one
     *   would let a child's outcome answer a park it was never for.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws SignalRefusedException
     */
    protected function deliverSignal(int $id, string $signal, array $payload, DateTimeImmutable $at, ?StepExecutionModel $row): void
    {
        $status = WorkflowExecutionModel::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->value('status');

        if ($status === ExecutionStatus::Waiting->value) {
            // Asked again under the lock when the first read found
            // nothing: a park that committed while this transaction
            // waited for the row has the step waiting now. A plain read
            // — the row lock already orders it, and a locking one here
            // would take step after execution, the reverse order.
            $row ??= StepExecutionModel::query()
                ->where('workflow_execution_id', $id)
                ->where('status', StepStatus::Waiting->value)
                ->where('wait_signal', $signal)
                ->first(['id', 'wait_signal']);

            // The database match is case-insensitive on MySQL, MariaDB,
            // and SQL Server; the reserved prefix is an authorization
            // boundary, so the name must match byte for byte.
            if ($row !== null && $row->wait_signal === $signal) {
                StepExecutionModel::query()
                    ->whereKey($row->id)
                    ->where('status', StepStatus::Waiting->value)
                    ->update(['output' => $this->asJson($payload)]);

                WorkflowExecutionModel::query()
                    ->whereKey($id)
                    ->update($this->counted(['next_retry_at' => $at]));

                return;
            }
        }

        if (! is_string($status)
            || str_starts_with(mb_strtolower($signal), Wait::RESERVED_PREFIX)
            || in_array($status, [
                ExecutionStatus::Completed->value,
                ExecutionStatus::Cancelled->value,
                ExecutionStatus::Failed->value,
                ExecutionStatus::Compensating->value,
            ], true)) {
            throw SignalRefusedException::forSignal($id, $signal);
        }

        $values = ['name' => $signal, 'payload' => $payload, 'received_at' => $at];
        $key = ['workflow_execution_id' => $id, 'name_hash' => SignalInboxModel::hashName($signal)];

        // Latest wins: one row per execution and name, overwritten by a
        // later delivery, so the inbox is bounded by the names a sender
        // uses, not by how often it sends. Update first; insert when
        // there was nothing to update; and an insert that lost a race
        // to the unique key — possible on SQLite, which took no lock
        // above — becomes the update it should have been.
        $existing = SignalInboxModel::query()->where($key)->first();

        if ($existing !== null) {
            $existing->fill($values)->save();

            return;
        }

        try {
            SignalInboxModel::query()->create($key + $values);
        } catch (UniqueConstraintViolationException) {
            SignalInboxModel::query()->where($key)->firstOrFail()->fill($values)->save();
        }
    }

    /**
     * Remove and return the buffered payload for the application signal
     * $step is about to park on, or null when there is none. A timed
     * wait and the machinery's waits never consume the inbox.
     *
     * @return array<array-key, mixed>|null
     */
    protected function takeBufferedSignal(int $executionId, StepExecution $step): ?array
    {
        $signal = $step->waitSignal;

        if ($signal === null || str_starts_with(mb_strtolower($signal), Wait::RESERVED_PREFIX)) {
            return null;
        }

        // A locking read: on MySQL under REPEATABLE READ a plain read
        // would answer from the snapshot this transaction began with,
        // before a signal that committed while the claim guard waited.
        $row = SignalInboxModel::query()
            ->where('workflow_execution_id', $executionId)
            ->where('name_hash', SignalInboxModel::hashName($signal))
            ->lockForUpdate()
            ->first();

        if ($row === null || $row->name !== $signal) {
            return null;
        }

        SignalInboxModel::query()->whereKey($row->id)->delete();

        return $row->payload;
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
        // A step's input is written once, by the machinery that
        // declares it (a fan-out's member list), and never erased. The
        // runner holds its own handle for the same row, and its
        // checkpoint of that handle — whose input is null — must not
        // clobber what the machinery wrote under the same claim a
        // moment earlier; so null here means "leave it", not "clear it".
        $input = $step->input === null ? [] : ['input' => $this->asJson($step->input)];

        StepExecutionModel::query()
            ->whereKey($step->id)
            ->update($input + [
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

    /**
     * Consult the tenant's ceilings for the execution about to be
     * claimed. Either ceiling reached: record the deferral — when
     * delivery is next tried, and one more refusal — in one counted
     * write that touches nothing a claim protects, and refuse. Nothing
     * about the execution's status changes and no attempt is recorded.
     *
     * The count is a read followed by the claim's own compare-and-set,
     * not one atomic decision: two claimants for one tenant in the same
     * instant can both count below the ceiling. Honest about the
     * instant, exact in the steady state (docs/tenancy.md, "Why not
     * exact").
     *
     * @throws TenantThrottledException
     */
    protected function assertWithinTenantCeilings(WorkflowExecutionModel $before, DateTimeImmutable $now, DateTimeImmutable $staleBefore): TenantLimits
    {
        $tenant = (string) $before->tenant_id;
        $limits = $this->ceilings->limitsFor($tenant);

        if (! $limits->bounded()) {
            return $limits;
        }

        if ($limits->maxConcurrent !== null) {
            // Executions holding a worker: running or compensating with
            // a fresh claim. A stale claim is a dead worker's, and a
            // dead worker holds no slot — counting it would let one
            // crash lock a tenant out until recovery reclaimed it.
            $holding = WorkflowExecutionModel::query()
                ->where('tenant_id', $tenant)
                ->whereIn('status', [ExecutionStatus::Running->value, ExecutionStatus::Compensating->value])
                ->where('claimed_at', '>', $staleBefore)
                ->count();

            if ($holding >= $limits->maxConcurrent) {
                $this->defer($before, TenantCeiling::Concurrency, $limits->maxConcurrent, $holding, $now);
            }
        }

        if ($limits->maxClaimsPerMinute !== null && $this->ceilings->rateCeilingReached($tenant, $limits->maxClaimsPerMinute)) {
            $this->defer($before, TenantCeiling::Rate, $limits->maxClaimsPerMinute, $this->ceilings->claimsThisMinute($tenant), $now);
        }

        return $limits;
    }

    /**
     * Record the deferral and refuse — unless the row moved since it was
     * read, in which case nothing is written and nothing is thrown: the
     * world changed under the count, and the compare-and-set below is
     * the thing that knows what the row is now.
     */
    protected function defer(WorkflowExecutionModel $before, TenantCeiling $ceiling, int $limit, int $count, DateTimeImmutable $now): void
    {
        $delay = $this->ceilings->deferralSecondsFor($before->id);
        $until = $now->modify("+{$delay} seconds");

        $recorded = WorkflowExecutionModel::query()
            ->whereKey($before->id)
            // Guarded on the status the ceiling counted under: a claim
            // that landed in between cleared the columns and must not
            // have a stale deferral stamped over its run.
            ->where('status', $before->status)
            ->update($this->counted([
                'deferred_until' => $until,
                'deferrals' => new Expression('deferrals + 1'),
            ]));

        if ($recorded === 0) {
            return;
        }

        throw TenantThrottledException::atCeiling(
            executionId: $before->id,
            workflow: $before->workflow,
            correlationId: $before->correlation_id,
            tenantId: (string) $before->tenant_id,
            ceiling: $ceiling,
            limit: $limit,
            count: $count,
            deferredUntil: $until,
            delaySeconds: $delay,
        );
    }

    /**
     * Whether the CAS below could take this row as read — the mirror of
     * its predicate, for deciding who the ceilings apply to. A race that
     * changes the answer after this read is settled by the CAS itself.
     */
    protected function claimableNow(WorkflowExecutionModel $before, DateTimeImmutable $now, DateTimeImmutable $staleBefore, bool $ignoreRetrySchedule, bool $reexecuteFailed): bool
    {
        return match (ExecutionStatus::from($before->status)) {
            ExecutionStatus::Pending => true,
            ExecutionStatus::Failed => $reexecuteFailed,
            ExecutionStatus::Retrying => $ignoreRetrySchedule
                || $before->next_retry_at === null
                || $before->next_retry_at->toImmutable() <= $now,
            ExecutionStatus::Waiting => $ignoreRetrySchedule
                || ($before->next_retry_at !== null && $before->next_retry_at->toImmutable() <= $now),
            ExecutionStatus::Running, ExecutionStatus::Compensating => $before->claimed_at === null
                || $before->claimed_at->toImmutable() <= $staleBefore,
            ExecutionStatus::Completed, ExecutionStatus::Cancelled => false,
        };
    }

    protected function explainFailedClaim(int $id): never
    {
        $current = WorkflowExecutionModel::query()->find($id);

        if ($current === null) {
            throw ExecutionNotFoundException::forId($id);
        }

        $status = ExecutionStatus::from($current->status);

        // Compensating is claimed work exactly as running is — the
        // undo runs under a claim, and a fresh one is reclaimed on the
        // same stale test. Routing it to ExecutionNotRunnableException
        // told a caller a status the contract calls *unfinished* was
        // terminal, and had the queue job log a live pass as "already
        // finished (completed or cancelled)".
        if ($status === ExecutionStatus::Running || $status === ExecutionStatus::Compensating) {
            throw ExecutionAlreadyClaimedException::forId($id, $status);
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
            planVersion: $model->plan_version,
            persistedDurationMs: $model->duration_ms,
            deferredUntil: $model->deferred_until?->toImmutable(),
            deferrals: $model->deferrals,
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
            compensatesStepExecutionId: $model->compensates_step_execution_id,
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
