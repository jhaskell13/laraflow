# Laraflow Roadmap

Laraflow is a Laravel package for defining integrations and durable, multi-step workflows. The goal is to let Laravel applications connect external services and run automation with observable, recoverable execution rather than hand-rolled glue code.

This roadmap is ordered by dependency and risk. It describes intended product work, not promises or release dates. Keep the public API small until execution and persistence guarantees are proven.

## Current state

Milestone 0 is complete (October 2026). The repository has:

- Composer package with Laravel auto-discovery, merged configuration, automatically loaded migrations, and `laraflow-config` / `laraflow-migrations` publish tags.
- Connector, factory, manager, authentication, and a GitHub connector prototype.
- Workflow, step, in-memory context, workflow runner, execution repository, Eloquent persistence models, and initial migrations.
- Unit and feature tests for those foundations, including successful execution and persisted failure.
- Documentation: README with installation, configuration, first workflow, first connector, and current limitations; `docs/public-api.md` with stability levels and the decided runner API; `docs/compatibility.md`; `CHANGELOG.md`; `CONTRIBUTING.md`; `SECURITY.md`.
- Declared support for PHP 8.2–8.4 and Laravel 12 (Testbench 10); SQLite tested, MySQL/MariaDB/PostgreSQL expected to work pending CI.

Milestone 1 is complete (October 2026). The runner is a safe durable runtime: the full step plan is persisted before execution, executions are claimed by compare-and-set with stale-claim takeover, step outputs and the context snapshot are checkpointed transactionally and restored on recovery, the workflow definition is validated against the persisted plan, failure persistence is robust, and the at-least-once contract plus idempotency hooks are documented in `docs/execution-lifecycle.md`.

Milestone 2 is complete (October 2026). Executions can run on a Laravel queue (`WorkflowDispatcher` + `ExecuteWorkflowJob`, payload carrying only the execution id), duplicate deliveries resolve safely through the existing claim, the queue is given no retry authority by default so the two retry systems cannot multiply, `laraflow:recover` finds and retries interrupted work under explicit bounds, and eight lifecycle events fire after each durable write without carrying input, output, or context. The contract lives in `docs/queue-and-recovery.md`; `tests/Feature/Queue/QueueRecoveryTest.php` proves the exit criteria against a real queue driver and worker. No migration was required.

Milestone 3 is complete (October 2026). Steps can declare retry policy — attempts, capped exponential backoff with deterministic jitter, retryable-exception rules, and a per-attempt deadline — off by default. A retryable failure becomes durable `retrying` state with `next_retry_at` rather than an exception, delivered when due by a delayed queue job and, contractually, by `laraflow:recover`. Attempt history is append-only, timeouts are classified at the recovery boundary distinctly from worker loss, cancellation exists with cooperative semantics for running work, and an optional redactor governs every error message the package retains. The contract lives in `docs/retry-and-failure-policy.md`.

Milestone 4 is complete (October 2026). Applications inspect and operate executions through the `ExecutionRepository` contract alone — filtered, keyset-paginated listing with every criterion served from an index, batched reads so no listing costs a query per row — and every console command runs on that same surface, with a test reading each command's source to keep it there. Executions carry application-supplied correlation and tenant identifiers that reach steps, every lifecycle event, and Laravel's log context, which propagates into jobs a step dispatches. Events carry attempt and execution durations and queue lag measured from availability rather than dispatch. Retention is three tiers, all off by default, applied by `laraflow:prune`: scrub payloads, prune attempt history, delete — with a compare-and-set guard at every write, orphan-safe deletion that does not depend on database cascades, self-limiting scans, and a dry run that predicts the real run exactly. The contracts live in `docs/inspection-and-observability.md` and `docs/retention.md`.

Milestone 5 is complete (October 2026). The connector layer is a coherent capability rather than a prototype: connectors are registered once and resolved per named connection, credentials are resolved per request through a `CredentialProvider` and wrapped in a `Secret` that no PHP rendering can print, HTTP behaviour (base URL, timeouts, headers, transport retries, a client-side rate limit, a page budget, a pinned provider API version) resolves connector class → connection → `laraflow.http` defaults, responses keep their raw body and map onto five shared exception types, a provider's `Retry-After` drives the durable step retry, pagination is lazy and refuses rather than truncates, every call is announced and named in the log context, and `Laraflow\Testing\ConnectorContract` ships so third-party connectors are held to the same standard as the bundled GitHub and Slack ones. The contract lives in `docs/connectors.md`; a test fails if a bundled connector ever depends on anything internal.

Milestone 6 is complete (October 2026). CI proves the declared matrix — PHP 8.2–8.5 against Laravel 12 and 13, a `prefer-lowest` floor cell that must also pass static analysis, the full suite against MySQL 8.0, MariaDB 10.6, PostgreSQL 13, and SQL Server 2019, and an install job that walks a fresh `laravel/laravel` application from `composer require` to a persisted, recovered execution. The schema is frozen and the freeze is enforced by test; `docs/upgrading.md` is the upgrade/rollback/recovery contract, with `UpgradePathTest` as its harness. Versioning and deprecation are operational (`docs/compatibility.md`), the API document is enforced in both directions, and a tag-triggered workflow re-runs the matrix and drafts a release a human publishes. Laravel 13 support was added along the way — the engines and versions found ten real defects between them, the worst being compare-and-set guards misread on MySQL (affected-rows vs matched-rows, fixed with a version counter) and a `Retry-After` parsed in local time. The closing audit reviewed the entire project and fixed six more, including a release that could erase a concurrent cancellation request; what it deferred is recorded under Milestone 7.

The next horizon is Milestone 7, driven by real user needs.

## Product principles

- Laravel-first: use the container, queues, configuration, events, and Eloquent naturally.
- Durable by explicit contract: persisted state must be enough to explain and safely continue an execution.
- At-least-once execution at recovery boundaries: external side effects cannot generally be made exactly-once by the package. Provide idempotency tools and state this plainly.
- Serializable boundaries: persisted workflow inputs, step inputs, and outputs must be representable safely across processes and supported database drivers.
- Extensible without forcing a platform: synchronous use remains useful; queues, drivers, and integrations are opt-in.
- Useful failure visibility: users can inspect what ran, what failed, and what can be retried without reading internal tables.

## Milestone 0 — Make the package usable and establish contracts ✅

Completed October 2026. Kept for the record.

### Work

- Replace the placeholder README with installation, configuration, first connector, first workflow, persistence setup, execution examples, and current limitations.
- Define and document the public API boundaries: workflow definition, step definition, inputs/outputs, runner, execution handle, repository, and connector contracts.
- Decide naming and behavior for `run`, `start`, `execute`, and `resume` before more applications depend on the current synchronous API. *Decided: `start`/`execute`/`run` returning a `WorkflowExecution` handle; `resume` removed. Recorded in `docs/public-api.md`; implemented in Milestone 1.*
- Add config publishing and migration publishing/loading conventions, and document which approach the package supports.
- Add package metadata that Laravel users expect: license, support policy, changelog, contribution guide, and security contact/process.
- Record supported PHP, Laravel, and database versions as an explicit compatibility policy.

### Exit criteria

- A new Laravel user can install the package and follow a documented example from zero to a persisted execution.
- Public versus internal classes are identified; experimental behavior is labeled.
- Version support is deliberate rather than an incidental Composer constraint.

## Milestone 1 — Correct execution lifecycle and recovery semantics ✅

Completed October 2026, including the runner API decided in Milestone 0 (`start`/`execute`/`run`, `resume` removed). The lifecycle contract lives in `docs/execution-lifecycle.md`; the recovery suite in `tests/Feature/Workflow/WorkflowRecoveryTest.php` covers the exit criteria. Two Milestone 1 items were deliberately narrowed: step-level `input` persistence exists in the schema but workflows feed steps through the context, and per-attempt history is deferred to Milestone 3 where retry policy gives it meaning.

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

## Milestone 2 — Queue execution and operational recovery ✅

Completed October 2026. Queue concerns live in `Laraflow\Queue` and the events in `Laraflow\Events`; `WorkflowRunner` gained no queue dependency, so the lifecycle contract stayed the boundary rather than absorbing queue assumptions.

Two items were resolved more conservatively than the original wording implies. Recovery of never-dispatched `pending` executions ships **disabled by default**, because `pending` is also the state of work deliberately created-but-not-run via `start()`. And "retry eligible work" was scoped to *interrupted* work only: retrying genuinely **failed** executions needs the retry policy, backoff, and `next_retry_at` semantics of Milestone 3, so recovery deliberately does not touch them yet.

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

## Milestone 3 — Retry, timeout, and failure policy ✅

Completed October 2026. Three items were deliberately narrowed.

**Timeouts are deadlines, not preemption.** Laraflow records each attempt's deadline, exposes it to the step, and classifies an overrun attempt as a timeout charged to the retry budget — but it does not interrupt running PHP. A hung step on a live claim is reported and left alone until the claim ages out; bounding the process itself remains the queue worker's job.

**`FailurePolicy::Continue` is scoped to independent steps.** Later steps see earlier checkpoints but nothing from the failed step, and retry policy takes precedence over continuing. Compensation remains Milestone 7.

**The two attempt bounds stay independent.** `recovery.max_attempts` was renamed `recovery.abandon_after_attempts` and governs *interruption* recovery only; due retries answer to their step's own policy budget. Merging them would either strand long retry budgets or weaken crash-loop protection.

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

## Milestone 4 — Querying, observability, and lifecycle management ✅

Completed October 2026. Four items were deliberately narrowed or decided.

**Correlation is two indexed columns, not a metadata blob.** `correlation_id` and `tenant_id` are the identifiers; a free-form JSON column cannot be indexed portably across the four supported engines, and bounded listing was an exit criterion. Both are validated at the boundary — length, and control characters that would let an identifier forge a log line.

**Metrics are enriched events, not a metrics contract.** Durations and queue lag ride on the existing lifecycle events; the documentation gives listener recipes for Prometheus, StatsD, and Pulse. Shipping no vendor integration is how "does not assume a particular monitoring vendor" was met.

**No write-time payload redaction.** Input seeds the context on every `execute()` and the context is restored on recovery, so rewriting either at write time would corrupt re-runs. Retention offers post-finish scrubbing instead, and the lifecycle document says to store identifiers rather than secrets. The `errors.redactor` hook from Milestone 3 continues to govern error messages.

**Retention never scrubs failed executions, and deletes them only under their own bound.** Re-executing one needs its input and context; discarding recoverable work must be a decision, not a side effect of general retention. Attempt pruning does cover failed executions, because resumability reads the step row's counter rather than the history table — and the trade-off that creates is stated in `docs/retention.md` rather than left implicit.

The `ExecutionRepository` contract changed in several places (recorded in `CHANGELOG.md`); it remains one interface by decision, with the Eloquent implementation organised into per-concern traits.

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

## Milestone 5 — Integration framework hardening ✅

Completed October 2026. Five items were deliberately narrowed or decided.

**Errors are returned, not thrown.** An error status stays a result to inspect, as it was in Milestone 0; the response carries the mapped exception and `throw()` raises it, and a connection opts into throwing with `throw_on_error`. Changing the default would have turned every existing `failed()` check into an uncaught exception.

**A provider's `Retry-After` drives the durable retry, within a cap.** This extends the Milestone 3 retry contract: an exception implementing `SuggestsRetryDelay` is believed up to `retry.max_suggested_delay` and never below the policy's own backoff. The alternative — sleeping a worker for the provider's wait — is exactly what durable retries exist to avoid.

**Transport retries ship off, in milliseconds, and never wait for a long `Retry-After`.** They are the inner loop to the step policy's outer one, and the two are kept from multiplying by construction rather than by documentation alone.

**Connector events carry no correlation id.** A connector may be called outside any workflow; inside one, Laravel's log context already holds the identifiers and a listener reads them from there. Putting them on the event would have coupled the integration layer to the workflow layer for information already available.

**The contract test is a plain object, not a trait.** Adding `tests/` to static analysis to make a trait "used" surfaced 344 findings that belong to Milestone 6; a class using PHPUnit's static assertions is analysed as ordinary source, collides with nothing in the host test, and works from Pest.

The second connector is Slack, chosen for behaving nothing like GitHub: failure inside a `200`, cursor pagination with a per-endpoint result key, mostly non-idempotent POSTs. It was added only after the generic contracts were tested, as the work item asked.

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

## Milestone 6 — Compatibility, quality, and release readiness ✅

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

Hardening deferred from the Milestone 6 closing audit, to take before or alongside new capability work:

- ~~Claim-guard the step-row writes (`saveStep`) so a stale worker cannot scribble step status or attempt history before its first checkpoint fails (documented in `docs/execution-lifecycle.md`, *Known limits of the claim discipline*).~~ Done (step 1).
- ~~Bound the claim-to-first-step recovery loop (same document): a deterministic fault before the first step write is currently re-dispatched unbounded.~~ Done (step 2).
- ~~Fire `WorkflowExecutionDispatched` after the commit it waits for, instead of documenting that it may precede it.~~ Done (step 3).
- ~~Map connector errors before the observing transport announces the call, so `ConnectorRequestSent.successful` can reflect a rejected `200`.~~ Done (step 4).
- ~~Case-insensitive header merging across `HttpOptions` and the transport.~~ Done (step 5).

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

1. ~~Replace the README placeholder and write down the public API and compatibility policy.~~ Done.
2. ~~Specify execution/step transitions and recovery guarantees.~~ Done.
3. ~~Correct resume semantics, including restoring completed outputs/context and validating the stored plan.~~ Done.
4. ~~Add execution claiming/concurrency protection and crash-boundary recovery coverage.~~ Done.
5. ~~Separate execution creation from execution, then add Laravel queue dispatch.~~ Done.
6. ~~Add stale-execution recovery with explicit bounds.~~ Done.
7. ~~Add bounded retry policy for failed work — attempts, backoff, timeouts, cancellation.~~ Done.
8. ~~Stabilize inspection/observability APIs, including retention for the attempt history.~~ Done.
9. ~~Harden the connector contracts.~~ Done.
10. ~~Release automation and the schema freeze.~~ Done. Milestone 6 is complete; the roadmap's remaining horizon is Milestone 7.

## Out of scope until the core is proven

- Claiming exactly-once execution for arbitrary side effects.
- A built-in visual workflow designer or hosted control plane.
- Supporting every Laravel version/database/queue backend without CI evidence.
- A large catalog of connectors before connector extension points are stable.
- Complex branching or distributed orchestration before linear recovery is reliable.
