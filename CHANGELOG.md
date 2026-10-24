# Changelog

All notable changes to Laraflow are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until the first tagged release, breaking changes may land in any commit and are recorded under *Unreleased*.

## [Unreleased]

### Milestone 4 — querying, observability, and lifecycle management

*In progress.*

#### Added

- **Schema for correlation, queue lag, and retention** (existing migration edited in place, per the pre-release policy). `laraflow_workflow_executions` gains:
  - `correlation_id` and `tenant_id` — application-supplied identifiers tying an execution to the request, job, tenant, or external operation that caused it. Indexed columns rather than a metadata blob, because listing by them must stay a bounded indexed query on every supported engine.
  - `dispatched_at` and `available_at` — when the execution was handed to the queue, and when the queue was asked to make it runnable. These differ for a delayed retry, and queue lag (`claimed_at − available_at`) is meaningless without the distinction.
  - `finished_at` — one terminal timestamp written by `completed`, `failed`, and `cancelled` alike, and cleared when an execution runs again. Retention and "finished between" filters would otherwise need a three-way coalesce over `completed_at`/`failed_at`/`cancelled_at` that no index can serve.
  - `payloads_scrubbed_at` — when retention removed the execution's payloads, so a scrubbed record stays distinguishable from one that never carried any.
  - Indexes on `correlation_id`, `tenant_id`, `created_at`, `(workflow, status)`, and `(status, finished_at)`: every listing and retention filter the milestone will expose has an index behind it.
- **`Laraflow\Workflow\ExecutionOptions`** (public): the correlation and tenant identifiers an application records on an execution, supplied as an optional trailing argument to `WorkflowRunner::start()`, `WorkflowRunner::run()`, and `WorkflowDispatcher::dispatch()`. An options object rather than extra parameters so that recording more later does not change four signatures again. Validated at construction, before anything is persisted: trimmed, blank treated as absent (passing a missing request header is safe), rejected over 255 characters (silent truncation leaves an identifier that correlates with nothing), and rejected when containing control characters (these values reach log records, where a newline would let a crafted identifier forge a second line). Violations raise the new public `InvalidExecutionOptionsException`.
- **Identifiers reach every sink.** `WorkflowContext::correlationId()` and `::tenantId()` expose them to steps — read from durable state, so a step re-run by a recovering worker sees the originals rather than whatever ambient context that worker is in — and **all ten lifecycle events** carry them as trailing `correlationId`/`tenantId` properties. They are set at creation and never rewritten; `execute()` has no options parameter, so an execution's identity cannot change under recovery.
- **A stable listing API** on `ExecutionRepository`, so applications and consoles no longer need the internal Eloquent models: `list(ExecutionFilter, $limit, $cursor): ExecutionPage` and `count(ExecutionFilter): int`. `ExecutionFilter` (public) covers workflow(s), status(es), correlation id, tenant id, half-open creation and finish windows (inclusive start, exclusive end, so consecutive windows neither overlap nor leave a gap), and sort direction — and **every criterion is served by an index**, enforced by the migration test; a filter Laraflow cannot bound is a filter it does not offer. Pagination is **keyset**, not offset: the cursor identifies the last row of a page and the next page continues strictly past it, so a listing stays correct while rows are being inserted and costs the same on page 1000 as on page 1. Page size is clamped to `ExecutionRepository::MAX_PAGE_SIZE` (500), a constant on the contract so no implementation can forget the bound; the page reports the limit actually applied. A malformed cursor is rejected rather than guessed at.
- `ExecutionRepository::attemptHistory(WorkflowExecution)`: the attempt history of every step in one query, ordered by step position then attempt, using the execution id the attempts table has denormalised since Milestone 3 for exactly this purpose. `laraflow:show --attempts` moves onto it in step 5.
- **`laraflow:summary`** (experimental): execution counts by status for a scope (`--workflow`, `--correlation`, `--tenant`, `--since`, `--until`), followed by the three conditions worth attention — retries that are due, running steps past their deadline, and the oldest unfinished execution. **Exits non-zero when anything needs attention**, so a scheduler or deploy gate can use it as a health check. Not a per-workflow breakdown: that needs a `GROUP BY` the repository contract does not expose and should not gain for a console view, so `--workflow=` scopes the same numbers through an indexed filter instead.
- **Durations on lifecycle events.** `WorkflowStepCompleted` and `WorkflowStepFailed` carry `durationMs` for the attempt — the same `StepAttempt` record that goes to history feeds the event, so telemetry and history can never disagree about how long a step took. `WorkflowExecutionCompleted`, `WorkflowExecutionFailed`, and `WorkflowExecutionCancelled` carry `durationMs` from the execution's **first** start to this finish: end-to-end latency, deliberately including time spent failed or backing off between retries, which is what an SLA measures. Null when the execution never started (a `pending` execution cancelled outright); zero is a measured zero, not an absence. `WorkflowExecution::durationMs()` exposes the arithmetic.
- **`ExecutionStatus::isFinished()`** and `WorkflowExecution::isFinished()`: true for `completed`, `failed`, and `cancelled` — the states that record `finished_at`. Named for "has stopped", **not** `isTerminal()`: in Laraflow "terminal" means never claimable again (`completed`, `cancelled`) and `failed` is re-executable by design, so conflating them would let the retention work in step 10 either skip failed executions forever or delete work a worker still owns.
- **Queue lag.** Every dispatch records `dispatched_at` and `available_at` on the execution (the same instant for an ordinary push; the due time for a delayed retry), and `WorkflowExecutionStarted` gains `queueLagSeconds`: the wait from availability to the claim. Measured from *availability*, not dispatch, so a retry's own backoff is never counted as time waiting for a worker. Null when the execution was never dispatched or was claimed ahead of availability — running early is not a wait of zero. `laraflow:show` reports *Dispatched*, *Available* (only when it differs — a delayed retry), and *Queue wait*, open-ended while pending and exact once claimed. The dispatch is recorded before the job is pushed, so the `sync` driver sees it too. `WorkflowExecution::queueLagSeconds(DateTimeImmutable $claimedAt)` exposes the arithmetic. Specified in `docs/queue-and-recovery.md`.
- `WorkflowExecution` gains `correlationId`, `tenantId`, `dispatchedAt`, `availableAt`, `finishedAt`, and `payloadsScrubbedAt`. `markCompleted()`, `markFailed()`, and `markCancelled()` set `finishedAt`; `markRunning()` clears it, so a re-executed failure cannot look eligible to retention while a worker is running it. The claim and the compare-and-set cancellation path make the same writes directly.

#### Changed

- **Breaking:** `ExecutionRepository::create()` gained a trailing optional `?ExecutionOptions $options`. Custom implementations must accept and persist it; implementations that ignore it silently drop the identifiers an application supplied.
- **`laraflow:list` runs on the public query API** and no longer touches the internal Eloquent models, gaining `--workflow`, `--correlation`, `--tenant`, `--since`, `--until`, `--cursor`, and `--oldest`; `--status` and `--workflow` are repeatable. It reports how many of the matching set the page showed — a bounded page that does not say so looks like the whole truth — and prints the command that continues it, carrying the current filter forward so following the hint cannot silently widen the query. A malformed cursor and an unparseable time are both refused with guidance rather than guessed at. `laraflow:show` gains the correlation and tenant identifiers, reads its "Finished" row from `finished_at` (so cancellation is reported like completion and failure), and reads attempt history in one query instead of one per step.
- **Breaking:** `ExecutionRepository` gained `recordDispatch(id, dispatchedAt, ?availableAt)`. Custom implementations must add it; an implementation that ignores it leaves `queueLagSeconds` permanently null and `laraflow:show` silent about queue timing. It must not be claim-guarded, and must treat an availability earlier than the dispatch time as the dispatch time.
- **Breaking:** `ExecutionRepository` gained three methods — `list()`, `count()`, `attemptHistory()` — and the `MAX_PAGE_SIZE` constant. Custom implementations must add them. Each must honour the contract's bounds: `list()` may never return more than `MAX_PAGE_SIZE` rows and must paginate by keyset in the filter's direction; `count()` counts the whole matching set regardless of pagination.
- **Fixed:** documentation that had drifted behind Milestone 3 — the README described two tables (there are three) and listed only the four original execution statuses; `SECURITY.md` still cited "there is currently no retry limit" as unimplemented hardening and promised that "future" events and logging would redact step data, both of which shipped; `CONTRIBUTING.md` still gated contributions on Milestone 1; and `docs/queue-and-recovery.md` disagreed with `docs/public-api.md` about which events fire once per execution.

### Milestone 3 — retry, timeout, and failure policy

#### Added

- **Per-step retry policy** (`Laraflow\Workflow\Policy\StepPolicy`, public): total attempt budget, exponential backoff capped at `max_delay`, `retryOn`/`dontRetryOn` matched by `instanceof`, and a per-attempt timeout. Declared on a step via the optional `HasStepPolicy` interface, on a workflow via `defaultStepPolicy()`, or globally via `laraflow.retry.*` — resolved in that order, **per step**, so one slow step can out-declare its siblings. **Retries ship disabled** (`retry.max_attempts` = 1), consistent with `queue.tries` and `recovery.pending_after`.
- **`retrying` state** on executions and steps, carrying a durable `next_retry_at`. A retryable failure persists the failed attempt, schedules the next one, releases the claim, and **returns** from `execute()` — nothing is thrown, nothing reaches `failed_jobs`, and `WorkflowExecutionFailed` does not fire, so alerting built on failures stays meaningful. Terminal failures keep the Milestone 1/2 contract unchanged.
- **Deterministic schedules.** Backoff jitter is derived by hashing the execution id and step position with the attempt number rather than drawn from randomness, so one logical retry always lands on the same instant. When the jitter window would cross `max_delay` it slides down to fit rather than being clipped — clipping bunched roughly half of all executions onto exactly the ceiling, in precisely the case jitter exists to prevent.
- **PSR-20 clock** (`psr/clock`, already a transitive dependency) injected through the runner, repository, and recovery service; the default binding wraps Laravel's `Date` facade, so `Date::setTestNow()` still governs every timestamp.
- **Two delivery paths for a due retry.** `ExecuteWorkflowJob` queues a delayed redelivery for `next_retry_at` (`retry.dispatch_delayed`, skipped on the `sync` driver where `delay()` is ignored and backoff would become immediate recursion), and `laraflow:recover` gains a due-retry scan — the *contractual* pickup, needing no queue and catching anything the fast path loses.
- **Append-only attempt history** (`laraflow_step_attempts`): every finished attempt keeps its outcome (`completed`, `failed`, `timed_out`, `interrupted`), error, timestamps, duration, and scheduled retry. Rows are written in the same transaction as the step state change they describe, so history can never disagree with it — including rolling back together on claim loss — and a unique `(step_execution_id, attempt)` index stops a duplicate delivery double-counting an attempt. Read via `ExecutionRepository::attempts()` or `laraflow:show --attempts`.
- **Step deadlines.** A policy's `timeoutSeconds` is persisted as `timeout_at` *before* `handle()` runs (a dying worker writes nothing, so the deadline must already exist) and exposed via `WorkflowContext::deadline()`/`remainingSeconds()`. Laraflow does not preempt running PHP; classification happens at the recovery boundary, giving the three-way distinction the roadmap asked for: a thrown failure, worker loss before the deadline (free re-run, recorded `interrupted`), and an overrun attempt (recorded `timed_out` with `StepTimeoutException`, charged to the retry budget).
- **Cancellation.** `WorkflowRunner::cancel()` and `laraflow:cancel {execution} {--reason=}`. Immediate and atomic for `pending`/`failed`/`retrying` — using the same compare-and-set predicate as claiming, so it cannot race a worker — voiding any scheduled retry. Cooperative for `running`: the owning worker stops at its next step boundary, so the step in flight finishes and checkpoints. `cancelled` is terminal and refused by `claim()`, `execute()`, `dispatchExecution()`, and recovery; repeat cancellations are idempotent no-ops.
- **Error redaction.** `laraflow.errors.redactor` names an `ErrorRedactor` applied at one chokepoint before any exception message is persisted, logged (the failed-job hook included, which previously logged raw messages), or emitted. The exception the application catches is untouched; a misconfigured class fails loudly rather than silently leaking.
- **Continue-on-failure.** `Workflow::failurePolicy()` returning `FailurePolicy::Continue` runs the remaining steps after a terminal step failure; the execution still ends `failed` and rethrows the first exception. Retry policy takes precedence over continuing. For independent steps only — later steps see no contribution from the failed one.
- **Events:** `WorkflowStepRetryScheduled` (attempt, budget, error class and message, due time, delay) and `WorkflowExecutionCancelled`; `WorkflowExecutionRetryScheduled` gains `retry_due` and `step_timeout` reasons.
- **Console:** `laraflow:execute --now`; `laraflow:show --attempts` plus retry schedule, cancellation detail, per-step next-retry, and an `(overdue)` marker on a running step past its deadline; `retrying`/`cancelled` colours and `laraflow:list` filtering.
- `docs/retry-and-failure-policy.md` specifying all of the above, and behavioural suites covering scheduling, due-gated claiming, queue delivery (against a real driver and `queue:work` worker), timeouts, cancellation, failure policy, redaction, and console output.
- Schema (existing migrations edited in place, per the pre-release policy): executions gain `next_retry_at` with a `(status, next_retry_at)` index, `cancel_requested_at`, `cancelled_at`, `cancel_reason`; steps gain `failed_at` and `timeout_at`; new `laraflow_step_attempts` table.

#### Changed

- **Breaking:** `ExecutionRepository` — `claim()` gains `$ignoreRetrySchedule` and refuses a not-due `retrying` execution with `RetryNotDueException`; `saveStep()`/`checkpoint()` take an optional `StepAttempt`; `attempts()`, `dueForRetry()`, and `requestCancellation()` added; `release()` now also covers the `retrying` and `cancelled` transitions.
- **Breaking:** `laraflow.recovery.max_attempts` renamed `laraflow.recovery.abandon_after_attempts` (`--max-attempts` → `--abandon-after`). With `retry.max_attempts` now existing as the failure budget, two same-named knobs with different scopes invited operators to confuse crash-loop protection with retry policy. The bounds are deliberately independent: due retries are exempt from the abandonment bound, whose job is interruption recovery.
- **Breaking (behavioural, opt-in):** with a retry policy enabled, `execute()`/`run()` can return a non-terminal `retrying` execution instead of throwing. Under the shipped defaults, behaviour is unchanged.
- **Fixed:** `StepExecution::markFailed()` accepted a timestamp and discarded it, so a failed step had no record of when it failed; it is now persisted to `failed_at`.
- **Fixed:** a declared-but-empty environment variable (`LARAFLOW_RETRY_MAX_ATTEMPTS=`) cast to `0` and was rejected by policy validation, turning a stray blank line in `.env` into an application that could not resolve the runner. Blank now means "use the default".
- **Fixed:** `laraflow:list --status` help text listed only the four original statuses. It and the invalid-status error now derive from the enum.
- `composer playground` runs `migrate:fresh`. Because migrations are edited in place before the schema freeze, an existing playground database silently missed new columns.
- The test suite enforces SQLite foreign keys, which the schema has declared since Milestone 0 but no test had exercised.

### Milestone 2 — queue execution and operational recovery

#### Added

- `Laraflow\Queue\WorkflowDispatcher` (public): `dispatch(Workflow, $input, ?connection, ?queue)` creates an execution and queues it; `dispatchExecution(id, ...)` queues an existing one for retry or recovery. Queue concerns live here rather than on `WorkflowRunner`, which keeps no queue dependency.
- `Laraflow\Queue\ExecuteWorkflowJob` (public): the queued job. Its payload carries **only the execution id** — the workflow class is read from the execution record, so a job can never disagree with durable state, and no workflow input reaches the queue backend.
- Eight lifecycle events in `Laraflow\Events` (public, `readonly`): `WorkflowExecutionDispatched`, `WorkflowExecutionStarted`, `WorkflowStepStarted`, `WorkflowStepCompleted`, `WorkflowStepFailed`, `WorkflowExecutionCompleted`, `WorkflowExecutionFailed`, `WorkflowExecutionRetryScheduled`. Each fires strictly after the durable write it describes.
- `laraflow:recover` command: finds interrupted executions (stale claims, and optionally never-dispatched pendings) and retries them, with `--dry-run`, `--sync`, `--limit`, `--max-attempts`, `--stale-after`, `--pending-after`, and `--workflow`. Exits non-zero when work was abandoned or an action failed, so a scheduled run surfaces stuck work through existing alerting.
- `--queue` on `laraflow:execute` and `laraflow:demo`.
- `ExecutionRepository::stale()` and `::pendingBefore()`: bounded, clock-free scans for recoverable work. `stale()` uses the same `claimed_at <= cutoff` comparison as `claim()`, so scanning and claiming cannot disagree about staleness.
- Configuration: `queue.*` (`connection`, `queue`, `tries`, `backoff`, `timeout`, `after_commit`), `recovery.*` (`limit`, `max_attempts`, `pending_after`), and `events.include_error_messages`.
- `docs/queue-and-recovery.md` specifying dispatch, the retry decision, duplicate-delivery semantics, recovery policy and bounds, and event payload safety.
- End-to-end coverage in `tests/Feature/Queue/QueueRecoveryTest.php` using a real database queue driver and a real `queue:work` worker, rather than `Queue::fake()`: async completion, crash-to-recovery from a checkpoint, duplicate deliveries, the abandonment bound, and the absence of retry multiplication.
- Dependencies: `illuminate/bus`, `illuminate/queue`, `illuminate/events`, and `illuminate/console` (the last was already used by the existing commands but never declared).

#### Decisions

- **The queue does not retry by default** (`queue.tries` defaults to 1). Queue retries and Laraflow step attempts would otherwise multiply on two unrelated schedules. Re-running a failed execution is an explicit operation until per-step retry policy arrives in Milestone 3.
- **Duplicate deliveries are no-ops, not failures.** The existing compare-and-set claim is the only authority; `ExecutionAlreadyClaimedException`, `ExecutionNotRunnableException`, and `ClaimLostException` are caught and logged so the job succeeds. No cache lock or `ShouldBeUnique` is involved.
- **Recovery is bounded by step attempts.** An execution whose steps reached `recovery.max_attempts` is reported as abandoned rather than re-dispatched, so a crash-looping workflow cannot be retried forever by the scheduler. The bound reuses durable `attempts`, so Milestone 2 required **no migration**.
- **Recovery refuses executions whose workflow class is gone** (reported as *unrecoverable*, exit code 1). A retry of a missing class fails before any step runs, so attempts never increment and the attempt bound alone could never stop a scheduled re-dispatch loop.
- **Recovery of `pending` executions is opt-in** (`recovery.pending_after` defaults to null). `pending` is also the state of work created with `start()` and deliberately not yet run; recovery must not start that behind the application's back.
- **Events never carry input, output, or context**, with no opt-in. Failure events carry the exception class and, unless `events.include_error_messages` is disabled, the truncated message — data the execution record already holds.
- **Event listeners are observational**: a throwing listener is logged and swallowed rather than altering execution state, aborting a dispatch, or masking a step's exception. Listeners needing retry semantics should be queued listeners.

#### Changed

- `WorkflowRunner` takes two optional trailing constructor arguments (an internal event emitter and an error-message flag). Construction is internal wiring; resolve the runner from the container.
- `laraflow:execute` now resolves the recorded workflow class through a shared resolver and, with `--queue`, validates it *before* queueing, so a missing class fails at the console rather than minutes later on a worker.
- README gained a "Running on a queue" section; its configuration sample now reflects every shipped key, and "Current limitations" has been rewritten for the post-Milestone-2 state.

### Console tooling and local playground

#### Added

- Experimental Artisan commands, auto-registered in console contexts: `laraflow:demo {--fail}` (runs a bundled demo workflow; `--fail` leaves a recoverable failed execution), `laraflow:list {--status=} {--limit=}`, `laraflow:show {execution}`, and `laraflow:execute {execution}` (executes or recovers an execution using its stored workflow class, refusing clearly when that class is gone).
- `Laraflow\Demo\DemoWorkflow` (experimental): reserve inventory → charge payment → send receipt, exercising context passing, checkpointed outputs, attempt metadata, and idempotency keys.
- Local playground: `composer playground` prepares a SQLite database via Testbench (`testbench.yaml`), after which the commands run from the repository with `vendor/bin/testbench` — documented in the README's "Try it locally" section.
- Command test suite (asserting on the full captured output buffer; `tests/Support/RunsArtisanCommands.php` documents why `expectsOutputToContain` is avoided: its per-line Mockery matchers silently miss when two expected substrings share one output line).

### Code audit — quality, tooling, and test depth

#### Added

- Laravel Pint (`pint.json`: Laravel preset, `declare(strict_types=1)`, imported globals) and PHPStan/Larastan at level 8 (`phpstan.neon`), wired as `composer lint`, `composer analyse`, `composer format`, and `composer check`. A GitHub Actions workflow runs all checks on PHP 8.2, 8.3, and 8.4.
- `WorkflowRunner::start()` validates the workflow definition before persisting: an empty step list or a step that is not an existing `Step` class raises `InvalidWorkflowDefinitionException`.
- `GithubTokenAuthenticator` rejects an empty token at construction with a `ConnectorException` naming the config key, instead of silently sending `Bearer `.
- `ConnectorNotRegisteredException` and `InvalidConnectorException` (both `ConnectorException` subclasses) replace generic `InvalidArgumentException`s so every package error extends `LaraflowException`.
- `WorkflowContext::forget()`; `LaraflowManager::has()`; `ConnectorFactory::has()`.
- Tests: step resolution through the container with constructor dependencies; frozen-clock timestamps via `Date::setTestNow()`; provider-built GitHub connector sends the configured token end-to-end; missing-token failure; connector lazy build/caching; `ConnectorResponse` status boundaries; `vendor:publish` for both tags actually copies files; `migrate:rollback` drops the tables; `migrations.load=false` registers nothing; context null semantics; credentials absent from `TransportException`.

#### Changed

- `WorkflowRunner` receives the container and a PSR-3 logger by constructor injection instead of calling `app()` and the `Log` facade; all timestamps come from Laravel's `Date` facade.
- `GithubConnector` receives Laravel's HTTP client factory by injection instead of using the `Http` facade.
- The service provider autowires stateless singletons, reads configuration through the config repository, and uses `configPath()`/`databasePath()` for publishing.
- Eloquent models declare `$fillable`, use the `casts()` method, and carry `@property` annotations.
- `ConnectorRequest` and `ConnectorResponse` are `readonly` classes.
- **Fixed:** `WorkflowContext::get()` returned the default for a stored `null`; it now distinguishes a null value from an absent key.
- **Fixed:** `ConnectorFactory` error message said "did not return a factory"; it now names the expected and actual types.
- `phpunit.xml` fails on risky tests, warnings, notices, and deprecations, and splits Unit/Feature suites.

### Milestone 1 — execution lifecycle and recovery

#### Added

- `WorkflowRunner::start()` creates an execution and its **full step plan** up front, atomically, without running anything; `WorkflowRunner::execute()` claims an execution and runs every non-completed step, serving both first execution and recovery through a single code path.
- Compare-and-set execution claiming with per-worker claim tokens: only one worker can own an execution; `running` executions with a claim older than `laraflow.execution.stale_after` (default 300s) can be reclaimed after worker loss. Checkpoints and terminal writes verify the claim, so a superseded worker gets `ClaimLostException` instead of corrupting state.
- Transactional checkpoints: each completed step's output and the full context snapshot commit together; the context is restored from durable state on recovery, so later steps see earlier results without re-running them.
- Enforced execution and step state machines via `mark*()` transition methods (`InvalidStateTransitionException` otherwise), documented in `docs/execution-lifecycle.md` together with the full crash-boundary and at-least-once contract.
- Workflow-definition validation on `execute()`: a changed workflow class or step list raises `WorkflowDefinitionMismatchException` instead of silently reinterpreting durable state.
- Serialization contract enforcement: workflow input, step outputs, and context values must be JSON-serializable; violations raise `UnserializableValueException` naming the offending key. Input is validated before anything is persisted.
- Idempotency hooks on `WorkflowContext`: `executionId()`, `stepPosition()`, `attempt()`, and `idempotencyKey()` (stable across retries of the same logical step).
- Robust failure persistence: step failures store the exception class and truncated message (`laraflow.execution.max_error_length`) on both step and execution; if persisting the failure itself fails, the storage error is logged and the original exception still propagates.
- Workflow exception hierarchy under `Laraflow\Exceptions\Workflow`.
- Schema: executions gained `context`, `error`, `error_class`, `claim_token`, `claimed_at`, and status indexes; steps gained `error_class`. (Existing migrations edited in place per the pre-release schema policy.)
- Recovery test suite simulating worker death at every persistence boundary, claim contention, stale takeover, definition drift, serialization violations, and failure-persistence failure.

#### Changed

- **Breaking:** `WorkflowRunner::run()` now returns the `WorkflowExecution` handle instead of the `WorkflowContext`, and accepts workflow input that seeds the context (rationale in `docs/public-api.md`).
- **Breaking:** `WorkflowRunner::resume()` is removed; call `execute()`.
- **Breaking:** `ExecutionRepository` contract reworked: `create()` now takes the step plan; `claim()`, `checkpoint()`, and `release()` added; `save()` and `createStep()` removed. `find()` throws `ExecutionNotFoundException` instead of Eloquent's `ModelNotFoundException`.
- A step found `running` during recovery is treated as interrupted and re-runs (the documented at-least-once boundary); `started_at` now records the first start and survives re-execution; completing clears a previous error.

### Milestone 0 — contracts and packaging

#### Added

- Package migrations are now loaded automatically by the service provider, with an opt-out via `laraflow.migrations.load` / `LARAFLOW_LOAD_MIGRATIONS`.
- `laraflow-migrations` publish tag for applications that want to manage the migrations themselves.
- Package metadata: license, keywords, support links, `illuminate/database` dependency, `composer test` script.
- Documentation: installation and usage README, public API and stability levels (`docs/public-api.md`), compatibility and support policy (`docs/compatibility.md`), contributing guide, security policy, this changelog.
- `.gitattributes` enforcing LF line endings.

#### Changed

- The service provider now merges the package configuration (`mergeConfigFrom`), so `config('laraflow.*')` has defaults without publishing.
- The GitHub connector token is cast to a string when the configuration value is missing, so resolving the connector no longer raises a `TypeError`; requests without a token will fail at the GitHub API instead.

## Foundations (unreleased, prior to this changelog)

- Composer package with Laravel auto-discovery.
- Connector, factory, manager, and authenticator abstractions with a GitHub connector prototype.
- `Workflow`, `Step`, `WorkflowContext`, `WorkflowRunner`, execution and step value objects, `ExecutionRepository` contract with an Eloquent implementation, and initial migrations.
- Unit and feature tests for the above.
