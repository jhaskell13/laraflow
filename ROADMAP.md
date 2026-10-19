# Laraflow Roadmap

Laraflow is a Laravel package for defining integrations and durable, multi-step workflows. The goal is to let Laravel applications connect external services and run automation with observable, recoverable execution rather than hand-rolled glue code.

This roadmap is ordered by dependency and risk. It describes intended product work, not promises or release dates. Keep the public API small until execution and persistence guarantees are proven.

## Current state

The repository has an early foundation:

- Composer package and Laravel service provider with package auto-discovery.
- Connector, factory, manager, authentication, and a GitHub connector prototype.
- Workflow, step, in-memory context, workflow runner, execution repository, Eloquent persistence models, and initial migrations.
- Unit and feature tests for those foundations, including successful execution and persisted failure.
- PHP 8.2+, Laravel 12, and Testbench 10 are currently declared in Composer.

The runner currently executes synchronously. It persists execution/step state and exposes a `resume()` path, but this is not yet a safe durable runtime: completed step outputs are not restored into context, resume does not validate the workflow against persisted steps, worker ownership/concurrency is not coordinated, and no queue/recovery scheduler exists. A process death can leave records running. The README is still a placeholder, and there is no package usage, compatibility, or release documentation yet.

## Product principles

- Laravel-first: use the container, queues, configuration, events, and Eloquent naturally.
- Durable by explicit contract: persisted state must be enough to explain and safely continue an execution.
- At-least-once execution at recovery boundaries: external side effects cannot generally be made exactly-once by the package. Provide idempotency tools and state this plainly.
- Serializable boundaries: persisted workflow inputs, step inputs, and outputs must be representable safely across processes and supported database drivers.
- Extensible without forcing a platform: synchronous use remains useful; queues, drivers, and integrations are opt-in.
- Useful failure visibility: users can inspect what ran, what failed, and what can be retried without reading internal tables.

## Milestone 0 — Make the package usable and establish contracts

### Work

- Replace the placeholder README with installation, configuration, first connector, first workflow, persistence setup, execution examples, and current limitations.
- Define and document the public API boundaries: workflow definition, step definition, inputs/outputs, runner, execution handle, repository, and connector contracts.
- Decide naming and behavior for `run`, `start`, `execute`, and `resume` before more applications depend on the current synchronous API.
- Add config publishing and migration publishing/loading conventions, and document which approach the package supports.
- Add package metadata that Laravel users expect: license, support policy, changelog, contribution guide, and security contact/process.
- Record supported PHP, Laravel, and database versions as an explicit compatibility policy.

### Exit criteria

- A new Laravel user can install the package and follow a documented example from zero to a persisted execution.
- Public versus internal classes are identified; experimental behavior is labeled.
- Version support is deliberate rather than an incidental Composer constraint.

## Milestone 1 — Correct execution lifecycle and recovery semantics

This is the next engineering priority.

### Work

- Specify execution and step state machines, including valid transitions, timestamps, attempts, terminal states, and behavior for a worker that disappears while a record is running.
- Separate creation/scheduling from execution so an existing execution can be loaded and dispatched without creating duplicate records.
- Make resume rebuild the execution plan from durable state, skip only valid completed checkpoints, restore each completed output needed by later steps, and run remaining steps in the correct order.
- Persist inputs and outputs at well-defined boundaries. Define the serialization contract, null handling, payload limits, and behavior for unsupported values; do not persist arbitrary live PHP objects as a durability promise.
- Validate that the supplied workflow definition matches the persisted execution plan. Store a workflow version or immutable step snapshot so code changes do not silently reinterpret old executions.
- Make failure persistence robust even if persisting the failure itself fails; preserve the original exception and surface storage failure clearly.
- Define restart semantics for failed steps, stale running steps, completed executions, missing step classes, and missing workflow classes.
- Add concurrency protection for claiming or resuming an execution (transaction/row lock or compare-and-set), and document database-specific guarantees.
- Add idempotency keys or hooks for user steps that may repeat after a crash between an external side effect and checkpoint persistence.

### Exit criteria

- Recovery tests cover crashes between each persistence boundary, completed-step skipping, output/context reconstruction, stale-running policy, duplicate resume attempts, and workflow-definition drift.
- The documented guarantee accurately describes when a step may run more than once.
- Two workers cannot both successfully claim the same execution under supported database configurations.

## Milestone 2 — Queue execution and operational recovery

Build on the lifecycle contract, rather than embedding queue assumptions in workflow definitions.

### Work

- Provide Laravel queue integration to dispatch an execution by identifier and resolve the workflow from the container in the worker.
- Make dispatch idempotent and handle queue delivery duplicates.
- Define queue retry/timeout behavior alongside Laraflow attempts so the two retry systems do not multiply unpredictably.
- Add a scheduled recovery mechanism or Artisan commands to find stale executions and retry eligible work, with dry-run/reporting support where useful.
- Support queue connection/queue selection and normal Laravel worker configuration.
- Add lifecycle events for started, step started/completed/failed, execution completed/failed, and retry scheduled.
- Ensure event payloads avoid leaking credentials or sensitive step data by default.

### Exit criteria

- Users can start an execution asynchronously, inspect it, and recover work after worker interruption using documented Laravel tools.
- Duplicate queue deliveries are safe under the stated at-least-once contract.
- Recovery actions are visible and bounded; no automatic infinite retry loop.

## Milestone 3 — Retry, timeout, and failure policy

### Work

- Add per-step retry policy: maximum attempts, retryable exceptions, delay/backoff, and optional jitter.
- Use and populate `next_retry_at` consistently; avoid retrying before due time.
- Define timeout behavior and distinguish a timeout from ordinary failure and worker loss.
- Add workflow-level failure policy (stop, continue where explicitly supported, or compensation only if designed) without obscuring the default stop-on-failure behavior.
- Preserve attempt history rather than overwriting all useful diagnostic data on each retry.
- Provide explicit manual retry and cancellation operations with state validation.
- Keep secrets and sensitive exception data out of logs/events unless the application opts in.

### Exit criteria

- Retry schedules are deterministic in tests via a clock abstraction and do not conflict with Laravel queue retries.
- Operators can tell why a step will retry and when.
- Cancellation and manual retry behavior is defined for running and terminal executions.

## Milestone 4 — Querying, observability, and lifecycle management

### Work

- Expose a stable execution API for lookup, status, steps, attempts, errors, and timestamps; avoid making Eloquent models the required user-facing API.
- Add filters/pagination for listing executions by workflow, status, time, and application-supplied correlation/tenant identifiers.
- Add structured logging context and optional metrics hooks for duration, outcome, retries, and queue lag.
- Provide safe retention/pruning commands and policies, with clear handling of audit requirements and foreign keys.
- Document how to correlate an execution with a Laravel request, job, tenant, and external provider request.
- Add redaction hooks and configurable payload/error retention where appropriate.

### Exit criteria

- Applications can inspect and operate executions without direct SQL or package internals.
- High-volume installations have a documented retention strategy and bounded listing queries.
- Observability does not expose credentials or assume a particular monitoring vendor.

## Milestone 5 — Integration framework hardening

The connector layer should be a coherent, independently useful capability, not a collection of provider-specific special cases.

### Work

- Define connector registration/discovery, construction, authentication injection, request/response, error mapping, and configuration contracts.
- Make HTTP behavior configurable: timeouts, retries, rate limits, pagination, response decoding, and safe error reporting.
- Support Laravel's HTTP client conventions where practical and allow applications to replace transport/authentication.
- Add credential rotation and multiple named accounts/connections without embedding tokens in connector definitions.
- Add at least one more real connector only after the generic contracts are tested; establish a contribution recipe for future integrations.
- Document provider API versioning and compatibility expectations.

### Exit criteria

- A third-party package or application can register a connector without editing Laraflow internals.
- Connector tests run without real credentials or network access.
- Authentication secrets are not exposed by serialization, logs, or exception messages.

## Milestone 6 — Compatibility, quality, and release readiness

### Work

- Expand the CI matrix to supported PHP and Laravel versions and supported database engines.
- Add static analysis, formatting, dependency/security checks, and migration compatibility checks to CI.
- Test package installation and Laravel auto-discovery in a clean application fixture.
- Verify migrations on SQLite and production-relevant engines; document JSON, locking, and indexing requirements.
- Review indexes, foreign keys, cascade behavior, payload growth, and migration upgrade paths before stabilizing schema.
- Establish semantic versioning, deprecation policy, changelog/release workflow, and a supported-upgrade path.
- Mark unstable APIs and avoid a 1.0 promise until execution/recovery contracts and upgrade behavior are proven.

### Exit criteria

- CI proves the declared support matrix and package installation path.
- Schema changes have upgrade coverage and documented operational impact.
- A release can be installed, upgraded, and rolled back or recovered from using documented procedures.

## Milestone 7 — Advanced workflow capabilities (evaluate after core stability)

These are intentionally later and should be driven by real user needs:

- Branching, conditional steps, and fan-out/fan-in.
- Workflow composition and reusable sub-workflows.
- Delays, schedules, and human approval/wait states.
- Compensation/saga patterns for workflows that need business-level undo.
- Multi-tenant isolation and per-tenant throttling.
- Additional persistence or queue drivers only when the abstractions have real consumers.
- Optional dashboard or ecosystem integrations as a separate package or optional dependency.

Each feature needs explicit persistence, versioning, recovery, and observability semantics before implementation.

## Suggested immediate order

1. Replace the README placeholder and write down the public API and compatibility policy.
2. Specify execution/step transitions and recovery guarantees.
3. Correct resume semantics, including restoring completed outputs/context and validating the stored plan.
4. Add execution claiming/concurrency protection and crash-boundary recovery coverage.
5. Separate execution creation from execution and then add Laravel queue dispatch.
6. Add bounded retry and stale-execution recovery policies.
7. Stabilize inspection/observability APIs, connector contracts, and release automation.

## Out of scope until the core is proven

- Claiming exactly-once execution for arbitrary side effects.
- A built-in visual workflow designer or hosted control plane.
- Supporting every Laravel version/database/queue backend without CI evidence.
- A large catalog of connectors before connector extension points are stable.
- Complex branching or distributed orchestration before linear recovery is reliable.
