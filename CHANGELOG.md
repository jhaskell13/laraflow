# Changelog

All notable changes to Laraflow are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until the first tagged release, breaking changes may land in any commit and are recorded under *Unreleased*.

## [Unreleased]

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
