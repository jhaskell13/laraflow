# Changelog

All notable changes to Laraflow are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until the first tagged release, breaking changes may land in any commit and are recorded under *Unreleased*.

## [Unreleased]

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
