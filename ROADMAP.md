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

Milestone 7 is complete (October 2026), in two phases. Phase A closed all five hardening items deferred from the Milestone 6 audit: every step-row write is claim-guarded; a fault between claim and first step is bounded by the `fruitless_claims` counter rather than re-dispatched forever; `WorkflowExecutionDispatched` fires after the commit its push waits for; `ConnectorRequestSent.successful` is the connector's verdict rather than the HTTP status's; and header names merge case-insensitively at every layer. Phase B shipped three capabilities whose semantics were fixed in `docs/advanced-workflows.md` *before* implementation: conditional steps (a durable, attempt-free `skipped` state, replayed from the record), delays and wait states (a wait is the step's outcome boundary — resuming completes the step; signals carry payloads into the context; timeouts are ordinary step failures under the step's own policy), and sub-workflow composition (a wait on a reserved signal plus two linkage columns, with a parent-step retry resuming the same child and a `stale_after` heartbeat that makes the pairing self-healing). Three additive migrations rode the post-freeze upgrade harness. The phase closed with a three-way hostile audit — concurrency, code quality, contract alignment — that produced 48 findings and fixed 17 real defects, the worst being healthy heartbeats counted toward abandonment, a stale child-failure payload charged twice, and durable skips recorded against a non-final context under `FailurePolicy::Continue`. Fan-out/fan-in, compensation, and tenant throttling were deliberately deferred. The milestone — and the MVP — closed with a full-project audit back to the Milestone 0 foundations: seven parallel hostile reviews (concurrency, connectors and secrets, documentation alignment, code standards, test quality, schema and performance, and a security review) produced 190 findings; 41 code defects were fixed with regression tests, the worst a scalar step output that had crashed the checkpoint since Milestone 1, a reserved-signal guard that failed on case-insensitive engines, and two retention scans whose cost grew with history. The suite stands at 949 tests. Released as **0.2.0**.

Milestone 8 is complete (November 2026), in three phases, and released as **0.3.0**. Phase A closed the six design findings the Milestone 7 audit deferred: integer execution ids on every signature, one shape and one tenant scope for the four recovery scans, a backed `RecoveryReason`, required collaborators in place of nullable defaults, the wait state machine extracted into `WaitResolver`, and a `(status, created_at)` index for the pending scan. Phase B shipped the two foundational gaps the MVP had named but not closed, their semantics fixed in `docs/workflow-versioning.md` and `docs/idempotency.md` before any code: workflow versioning, so a deploy that changes a workflow carries its in-flight executions forward through a declared migration rather than stranding them — rewriting only the undecided tail of a plan, because a `pending` row is the one row that is not a record of something that happened; and a first-class idempotency store, where `remember()` commits its marker *before* the work runs, so a crash inside the window leaves positive evidence the work began and the next attempt is told the outcome is uncertain rather than silently repeating a charge or silently skipping one. Phase C made the package installable as a story rather than a README: `laraflow:install`, `make:workflow`, `make:step`, `make:connector`, and `laraflow:start`, which runs any workflow class and without which no install walk could be proved with the generators alone. CI grew to match — every engine now runs both ends of the dependency axis and ends with the install walk against itself, so the third exit criterion is held by a machine on MySQL, MariaDB, PostgreSQL, and SQL Server alike.

Each phase closed with the same three-way hostile audit — concurrency, code quality, contract alignment. Phase A found one defect (the recovery limit was never shared past the stale scan, since Milestone 2). Phase B found 54 findings and two criticals: the idempotency store could recurse until the worker died on the three engines that compare strings case-insensitively, and failed on PostgreSQL inside any transaction. Phase C found four: a sync queue connection ran the job inside the dispatch call while the command said "queued, start a worker"; `laraflow:execute` reported a parked execution as completed, having never learned the wait state Milestone 7 added; whether the job ran or the push failed was decided from configuration rather than from the record, so a broker outage was reported as a step failure with every step still pending; and a runtime refusal was reported as a step failure that needed recovering. It also found two CI assertions that could not fail and two generator stubs that the package's own code style immediately rewrote. The suite stands at 1052 tests.

Milestone 8.5 is complete (November 2026) and released as **0.4.0**: the inspection dashboard, which is the console's surface rendered in a browser and held there by the same test that forbids a command to reach past the public contract. Three pages — an overview of counts and what needs attention, a filterable list, and one execution in full with its steps, attempts, waits, lineage, and remembered keys — plus four actions that are the browser forms of `laraflow:execute`, `laraflow:cancel`, `WorkflowRunner::signal()`, and `laraflow:start`, each through the service the command calls. It is off until an application calls `Dashboard::routes()` and denied until `Dashboard::auth()` or a `viewLaraflow` gate says who may; mounted in production without a rule it denies everyone, which CI proves against a real application over real HTTP alongside the claim that an unmounted application has no routes at all. There is no build step anywhere: one hand-written stylesheet and one script, served by the package, with filters that apply as they are typed and pages that refresh themselves, and every page a full server render without them. The milestone also pulled five shared definitions out of the console — formatting, filter parsing, workflow-name resolution, JSON-object parsing, and what a finished run amounts to — plus the attention scan behind `laraflow:summary`, so the terminal and the browser cannot disagree. Its closing audit produced findings in every category it looked at and fixed six real defects, the worst two in the script: restoring focus after a live filter renamed one status filter to another, and a flag set on every action left the refresh dead for good after a back-navigation. The suite stands at 1115 tests.

Milestone 9 is complete (November 2026) and released as **0.5.0**: parallelism and compensation, the two capabilities Milestone 7 deferred, each written into `docs/advanced-workflows.md` before a line of it existed. Two decisions carried the milestone. A **fan-out is one plan step** whose members are child executions, so the plan stays the flat validated list it has been since Milestone 1 and graph branching stays out of scope — a member is a child with the same linkage, depth bound, cascade, and retention predicates a sub-workflow's child has, the member list is read once and recorded before any member exists so creation is resumable, and the join is a wait on a reserved signal that heartbeats like a single child's park. The join waits for all (`WaitAll` is the only policy that ships; cancelling siblings is a destructive reaction nothing asked for), and a retry resumes the members that did not complete rather than creating a second group. Deciding about a group costs the same at a thousand members as at three, by one indexed aggregate and a test that asserts the two query counts *equal*. A **compensation is an ordinary step row** appended after the plan with a link to what it undoes, which buys attempts, retries, timeouts, the attempt history, `laraflow:show`, and the dashboard with no new machinery — the saga is inspectable because it *is* steps, and the plan is the rows that compensate nothing. A failing workflow undoes what completed, in reverse, skipping the step that threw, each undo retried under the compensated step's own policy and the pass stopping at the first it cannot finish rather than reaching past it. Neither a fan-out nor a sub-workflow compensates its children: that would be acting at a distance on executions a parent does not own. Two additive migrations and one new `ExecutionStatus` case, `compensating` — the first since that enum promised more might be added. The closing audit ran two hostile reviews and found two criticals of the same shape: the new status was written durably and then stranded, unclaimable and uncancellable after a crash, and an exhausted undo rested the execution nowhere at all. Nine further defects were fixed, and five documents had not followed the code. Closure also took a deprecation that had drifted: the five `REASON_*` constants, announced in 0.3.0 for removal in 0.4.0, went. The suite stands at 1163 tests.

**A full-project audit back over Milestones 0–9 followed the 0.5.0 release**, across security, concurrency, contract alignment, and code quality. Security came back clean on the surfaces that carry the most: the reserved signal prefix is enforced case-insensitively at the single public entry, so the dashboard's signal action cannot reach a child's or a join's; the dashboard's start form resolves a name to a `Workflow` subclass before anything is constructed; `Secret` keeps its value off the object and out of any serialized copy; the paginator refuses a next-page link whose host, scheme, or port differs from the connection's; and every raw SQL fragment in the package is parameterised or constant. Four code defects were found and fixed with the tests that would have caught them, three of them in paths Milestone 9 had just added and one much older. The oldest and worst: the retention delete tier could remove a finished fan-out member while its parent was still parked on the join — and since the join decides from a count of the rows that exist, pruning the members that *failed* would have turned a group that should fail into one that completes. `docs/retention.md` had promised that could not happen, on reasoning that was true when a parent waited on one child. The other three: a compensated execution's failure event named `WorkflowException` rather than what actually broke; a live compensation pass was refused as a *finished* execution, against what `execution-lifecycle.md` already specified; and a second fan-out under one parent summarised no members at all. The audit also documented one boundary it decided not to move — `request()` sends the URI an application gives it, absolute host and all, where `pages()` checks — and removed the `REASON_*` constants that 0.4.0 should have. The suite stands at 1167 tests.

Milestone 10 is complete (November 2026) and released as **0.6.0**: schedules, triggers, and the operator's edge, each contract written before its code. A **tick is a row** — the unique `(schedule, instant)` index is the whole "exactly one execution per tick across any number of schedulers" guarantee, committed in one transaction with the execution it starts, with missed ticks a declared policy (skip and count, or catch up oldest-first under a bound and a window), overlap suppression opt-in and recorded, and the race proven at the claim in the suite and across three real `laraflow:schedule` processes per engine in CI. A **trigger is a signed POST**: HMAC-SHA256 over timestamp, execution, signal, and body, verified before any read, every refusal one `401`, no environment bypass, no inbox — plus named provider endpoints behind the provider's own verifier and the application's mapping to one signal. A **tenant ceiling lives in `claim()`** and nowhere else — concurrency and claims-per-minute, a refusal a recorded deferral rather than a failure, fairness stated as a sizing rule and held by a test where a two-hundred-execution flood delays the other tenant not one round more than a twenty-execution one. The console grew `laraflow:signal`, `laraflow:children`, bulk cancel and re-run by filter (a filter required — no form acts on everything), and `--json` on every operating command through one serializer and one envelope, which the install walk consumes with `jq`. Milestone 8's deferred per-tenant rate-limit bucket closed first, on a new ambient `ExecutionScope`. The closing audit — four hostile reviews scoped to the dashboard and Milestones 9–10 — produced findings in every category and fixed twelve defects with their regression tests, the worst three being schedule instants stored in UTC wall-clock that the rest of the package read in app time (every non-UTC application's schedules drifted by the offset), tenant ceilings consulted before claimability (a pinned tenant's duplicate deliveries were *deferred* — including a 15-second re-queue loop for a completed execution), and a parseable-but-impossible cron expression crashing the scheduler, the listing, the summary, and the overview at once. One boundary was documented rather than moved: tenant ids on case-insensitive engine collations, recorded in `docs/tenancy.md` the way the connector host guard's boundary is. The suite stands at 1311 tests.

The MVP is complete. The road to 1.0 is laid out below, after the milestone records.

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

## Milestone 7 — Advanced workflow capabilities ✅

Hardening deferred from the Milestone 6 closing audit, to take before or alongside new capability work:

- ~~Claim-guard the step-row writes (`saveStep`) so a stale worker cannot scribble step status or attempt history before its first checkpoint fails (documented in `docs/execution-lifecycle.md`, *Known limits of the claim discipline*).~~ Done (step 1).
- ~~Bound the claim-to-first-step recovery loop (same document): a deterministic fault before the first step write is currently re-dispatched unbounded.~~ Done (step 2).
- ~~Fire `WorkflowExecutionDispatched` after the commit it waits for, instead of documenting that it may precede it.~~ Done (step 3).
- ~~Map connector errors before the observing transport announces the call, so `ConnectorRequestSent.successful` can reflect a rejected `200`.~~ Done (step 4).
- ~~Case-insensitive header merging across `HttpOptions` and the transport.~~ Done (step 5).

Milestone 7's capability scope was decided in October 2026 and its semantics fixed in `docs/advanced-workflows.md` before implementation; all three capabilities shipped the same month: conditional steps (step 7), delays/wait states with signals (step 8), and sub-workflow composition (step 9), each with persistence, recovery, and observability semantics decided first and enforced by `tests/Feature/Workflow/{ConditionalStepTest,WaitStateTest,SubWorkflowTest}.php`. Fan-out/fan-in, compensation/saga, and multi-tenant throttling are deferred past this milestone, to be driven by real user needs.

**Deferred from the closing audit** (design findings judged real but not worth the churn before 1.0; recorded here as candidates for the first post-1.0 minor — in the event, Milestone 8 took all but the rate-limit bucket, which moved to Milestone 10): rename the four recovery scans to one shape (`stale`, `pendingBefore`, `dueForRetry`, `waitingDue`) and give all four tenant scoping; a backed `RecoveryReason` enum in place of the string constants on `WorkflowExecutionRetryScheduled`; `int|string` execution ids on the public contract against an int-only implementation — commit to one; extract the wait/child-wait state machine out of `WorkflowRunner` into a `WaitResolver`; make every service collaborator required rather than nullable-with-defaults; a `(status, created_at)` index for the opt-in pending scan; the per-connection (not per-tenant) rate-limit bucket; and the engine matrix meeting Laravel 12 and `prefer-lowest`, which today run on SQLite only.

From the original list, still intentionally later:

- Branching and fan-out/fan-in (conditional steps on a linear plan shipped in Milestone 7; graph execution did not).
- Compensation/saga patterns for workflows that need business-level undo.
- Multi-tenant isolation and per-tenant throttling.
- Additional persistence or queue drivers only when the abstractions have real consumers.
- Optional dashboard or ecosystem integrations as a separate package or optional dependency.

Each feature needs explicit persistence, versioning, recovery, and observability semantics before implementation.

## The road to 1.0

**What 1.0 means.** A developer installs Laraflow with one Composer command and has a durable workflow running against their own database in minutes. The capabilities are ones real products need and real competitors ship — durable waits and approvals, composition, parallelism, compensation, schedules — built on recovery semantics that are documented, tested on every supported engine, and have survived production. The package solves orchestration, not API wrapping: connectors are an extension point, not the product. Documentation is complete and versioned, the project follows open-source practice end to end (conduct, contribution, security, support, release cadence), and the public API carries a stability promise backed by deprecation tooling. Every milestone below ends with the same discipline the first seven used: semantics written before code, contracts enforced by tests, a hostile audit before the record is written.

### Milestone 8 — Foundations for a stability promise ✅

The debt that must be gone before any API is frozen: the design findings the Milestone 7 closing audit deferred, and the two foundational gaps the MVP named but did not close.

#### Work

- ~~Workflow versioning: a persisted plan may be migrated to a new step list by an explicit, tested migration path, so a deploy that changes a workflow no longer strands its in-flight executions on the old code.~~ Semantics fixed in `docs/workflow-versioning.md` before implementation (step 8); shipped in step 9.
- ~~An idempotency store: a first-class, bounded `remember(key, fn)` for steps whose side effects must not repeat, built on the existing `idempotencyKey()`, so at-least-once stops being the application's problem to solve from scratch.~~ Semantics fixed in `docs/idempotency.md` before implementation (step 8); shipped in step 10.
- The deferred audit items, each its own step and taken first, because the two capabilities above land on the signatures they change:
  - ~~Commit to integer execution ids on the contract.~~ Done (step 1).
  - ~~One naming shape and tenant scoping for the four recovery scans.~~ Done (step 2).
  - ~~A backed `RecoveryReason` enum.~~ Done (step 3).
  - ~~Required collaborators instead of nullable defaults.~~ Done (step 4).
  - ~~Extract the wait and child-wait state machine from `WorkflowRunner`.~~ Done (step 5).
  - ~~A `(status, created_at)` index for the pending scan.~~ Done (step 6).
  - Per-tenant rate-limit buckets: **deferred to Milestone 10** at Milestone 8 planning.
  - Phase B closed the same way (step 10a): 54 findings, two critical — the idempotency store could recurse until the worker died on the three engines that compare strings case-insensitively, and failed on PostgreSQL inside any transaction — two defects in the store's release discipline and the migrator's judge-after-commit ordering, all fixed with their regression tests and a published `IdempotencyStoreContract`; the rest documentation accuracy and a design simplification of the store's key.
  - Phase A closed with a three-way hostile audit — concurrency, code quality, contract alignment — of the six steps: 36 findings, one defect (the recovery limit was never shared past the stale scan, since Milestone 2), fixed with its regression test; the rest documentation accuracy, duplication, and a derived rather than listed services rule. Done (step 6a). The connector layer has no view of the executing tenant, so the fix needs an ambient execution scope, and Milestone 10 is where the tenant fairness rule gets written; the change is additive configuration, so nothing at 1.0 depends on it.
- ~~The engine matrix meets Laravel 12 and `prefer-lowest`, and every engine job runs the install story too.~~ Done (step 13).
- ~~`laraflow:install`, `make:workflow`, `make:step`, and `make:connector` generators, so the first workflow is scaffolded rather than typed~~ (step 11) — ~~and `laraflow:start {workflow}`, the command that runs an arbitrary workflow class, without which no install story can be proved with the generators alone~~ (step 12).

Phase B's two capabilities had their semantics decided before any code, as Milestone 7's did: `docs/workflow-versioning.md` and `docs/idempotency.md` (step 8). The two decisions that shape everything downstream — a plan migration may rewrite only the undecided tail of a plan, because a `pending` step row has no output, no attempt history, and no context contribution; and `remember()` commits a marker *before* the work runs, so a crash leaves positive evidence that the work started and the ambiguity is raised rather than guessed.

#### How it closed

Sixteen steps between 1 and 2 November 2026, in the order planned at the start of the milestone: Phase A's six deferred findings first, because the two capabilities land on the signatures they change; the two contracts written before their code; then the capabilities, the generators, `laraflow:start`, and CI.

- **Steps 1–6, Phase A**, each deferred finding its own step, closed by the audit in step 6a.
- **Steps 8–10, Phase B**, semantics first (step 8), then workflow versioning (step 9) and the idempotency store (step 10), closed by the audit in step 10a and two engine findings CI returned in 10b and 10c.
- **Steps 11–13, Phase C.** The generators (11), `laraflow:start` (12), and the CI matrix and install walk (13), with one engine finding in 13a — MySQL keeps JSON object keys in its own order, the same lesson as 10c.
- **Step 14, the Phase C audit.** Four defects, all in the console's reporting of what became of a run, plus two vacuous CI assertions and two stubs that failed the package's own lint. Each fixed with the test that would have caught it; the lint test was confirmed to fail on a broken stub before it was kept, and the strengthened CI assertions were rehearsed against a deliberately failed execution.
- **Step 16, closure.** This record, `CHANGELOG.md` converted to 0.3.0 with an explicit *Breaking* section, and the first exit criterion checked by grepping every contract document for *known limit* and *deferred*: no such section survives in any of them, and every remaining use of the words is ordinary prose describing a runtime state or the `after_commit` mechanism.

Step 15 — *what CI found* — was consumed in place by 13a rather than as a separate step; the matrix was run and green before the audit began, which is why the audit's CI findings are assertions that could not fail rather than engines that disagreed.

Two engine-only fixes from the Phase B audit — the PostgreSQL savepoint in `EloquentIdempotencyStore::begin()` and the collation-collision refusal — are confirmed by CI since.

#### Exit criteria

- ~~No *known limit* or *deferred* section remains in any contract document.~~ Met: checked at step 16 by grep across `docs/` and the README; nothing remains but the README's *Current limitations*, which states shipped behaviour rather than deferred work.
- ~~An in-flight execution survives a workflow definition change through the documented migration path, on every engine.~~ Met: `tests/Feature/Workflow/WorkflowVersioningTest.php`, which runs on all five engines in CI.
- ~~The install job proves zero-to-persisted-execution with the generators alone.~~ Met: `.github/actions/install-walk`, run on SQLite at both supported pairings and at the end of every engine job.

### Milestone 8.5 — The inspection dashboard ✅

The first thing anyone asks for after "does it recover" is "show me". The package already answers it from the console — `laraflow:list`, `laraflow:show`, `laraflow:summary` — and every one of those runs on the public `ExecutionRepository` contract alone, with a test that keeps them there. A dashboard is that same surface rendered in a browser, and taking it now rather than at Milestone 11 means the operator view exists before parallelism and schedules make the records harder to read by hand.

#### Work

- ~~An opt-in, first-party UI inside this package rather than a separate one: a route group an application mounts where it likes, behind its own middleware, off unless mounted.~~ Done (step 2). A separate `laraflow/dashboard` package was the Milestone 11 plan; folding it in was right for the reason given — the thing that makes a dashboard safe, reading only the public contract, is the same thing that makes a separate package unnecessary.
- ~~Views over what the records already carry: executions filtered the way `laraflow:list` filters them, one execution in full with its steps, attempts, remembered work, wait, and plan version, and the counts and attention list `laraflow:summary` computes.~~ Done (steps 3–6). The attention scan was extracted from the command so both compute it once.
- ~~The operator actions the console already exposes, each going through the same service: execute, cancel, signal, and start — `laraflow:start`'s service is the start button, which is why it took correlation and tenant identifiers at Milestone 8.~~ Done (step 7).
- ~~Authorisation as the application's decision, with a gate the package defines and the application answers; nothing mounted by default, and no action reachable that the console does not already offer.~~ Done (step 2), and the audit added the converse: an action route refuses a request with no session, since a bare mount outside a group that supplies one leaves nothing checking where a `POST` came from.
- ~~No new query surface. If a view needs something `ExecutionRepository` cannot answer, the contract gains it deliberately, with its index — the Milestone 4 rule.~~ Held throughout. The one thing a page wanted and the contract could not answer — a list of distinct workflow classes for the filter — was answered with free text and a page-scoped suggestion list rather than a new scan, and the question is recorded in `docs/dashboard.md` instead.

#### How it closed

Eleven steps across 2 November 2026, contract first as every milestone since the seventh has been.

- **Step 1 — the contract.** `docs/dashboard.md` before a line of code: off unless mounted, denied unless allowed, nothing the console cannot do, redaction carried over, every page working without script, no build step, inspection rather than authoring.
- **Steps 2–3 — the foundations.** The mount, the authorization rule and its middleware, the `suggest` entries and the guard that names them; then the layout, the committed stylesheet, the asset route, and the executions list.
- **Steps 4–6 — the views.** Filters and the cursor trail, one execution in full, and the overview with `AttentionScan` extracted from `laraflow:summary`.
- **Step 7 — the actions**, and **step 8 — refresh and confirmation**, the second of which brought the script the live filters later built on.
- **Step 9 — CI**, four stages proving absence and denial against a real application.
- **Step 10 — the audit.** Three hostile reviews: security, code quality, contract alignment. XSS and authorization bypass were clean; six real defects were not, and each was fixed with the test that would have caught it. The two worst were in the script and were proven fixed by driving the served page and the served script through a real DOM rather than by reading them.
- **Step 11 — closure.** This record, the README section the dashboard had been missing, `CHANGELOG.md` as 0.4.0, and the cross-references from the observability, queue, advanced-workflow, and compatibility documents that a reader arriving there would have needed.

Styling polish and live filtering were taken between steps 8 and 9 at the user's request; the live filter loads in place rather than navigating, because a navigation would take the field out from under an operator mid-word.

#### Exit criteria

- ~~Every number and row the dashboard shows is obtainable from the public contract, enforced by the test that already holds the console to it.~~ Met: `ProjectContractsTest::test_no_console_command_reaches_past_the_public_contract` now reads `src/Dashboard`, `src/Support`, and `resources/views` as it reads `src/Console`.
- ~~The dashboard is absent from an application that has not mounted it: no routes, no assets, no queries.~~ Met: `tests/Feature/Dashboard/MountingTest.php`, and against a real application in `.github/actions/install-walk`.
- ~~An operator can take an execution from failed to recovered without leaving the browser, and the record afterwards is indistinguishable from the console's.~~ Met: `tests/Feature/Dashboard/ActionsTest.php`, which drives the real runner and asserts the persisted record.

### Milestone 9 — Parallelism and compensation ✅

The two capabilities deferred from Milestone 7, each specified in `docs/advanced-workflows.md` before a line of code.

#### Work

- Fan-out/fan-in: a step group whose members run in parallel as child executions and whose join has defined semantics for partial failure, cancellation, and retry — the plan stays persisted and validated, the join is a wait, and the recovery contract gains no new windows. Semantics fixed in `docs/advanced-workflows.md` before implementation (step 2).
- Compensation: a step may declare `compensate()`; a failing workflow runs compensations in reverse over the steps that completed, with each compensation a durable, retryable step attempt and the saga's state inspectable like any other. Semantics fixed in the same document (step 3).
- Both ride the heartbeat and signal machinery that composition proved, not new mechanisms.

The two decisions that shape everything downstream, taken in the contract: a fan-out is **one plan step** whose members are child executions, so the plan stays a flat validated list and graph branching stays out of scope; and a compensation is **an ordinary step row** appended after the plan, so it is retryable and inspectable without a line of new machinery — the plan being the rows that compensate nothing. Milestone 8.5's audit leftovers were taken first (step 1), because both capabilities land on the console and dashboard code they touched.

Sixteen steps across 2 November 2026, contract first as every milestone since the seventh has been.

- **Step 1 — the leftovers.** Milestone 8.5's deferred audit items, before the work that lands on them: `Laraflow\Support\Handoff`, which decides once what became of handing an execution to a queue that may be `sync`, for the four commands and three dashboard actions that had each been deciding it themselves — two of them wrongly.
- **Steps 2–3 — the contracts.** Both capabilities written into `docs/advanced-workflows.md` before a line of either existed, which is where the two load-bearing decisions were taken and where the refusals — no `FailFast`, no member compensation, no map-reduce, no re-planning — were argued rather than discovered.
- **Step 4 — the aggregate.** `ExecutionRepository::childProgress()` and the index that serves it, because a join that counted a thousand members by reading them would cost on every heartbeat exactly what the heartbeat exists to avoid.
- **Steps 5–6 — the fan-out.** Declare once, record before creating, create resumably, deliver, park on the join; then the join itself — `WaitAll`, the group's shape as the output, and a retry that resumes its members rather than charging them twice.
- **Steps 7–8 — the edges.** The cancellation cascade at width, and the heartbeat that makes a lost join signal or a lost member dispatch heal rather than hang.
- **Steps 9–11 — compensation.** The reverse pass over completed steps, each undo an appended step row under the compensated step's own policy, the `compensating` status, and the stop rule.
- **Step 12 — the scale proof.** A thousand members created once, cancelled mid-flight, and partially failed — and the claim of a parked join issuing the *same* query count at a thousand members as at three, asserted as equality, because "roughly constant" is how a linear cost hides.
- **Step 13 — legibility.** What `laraflow:show` and the dashboard say about a group and about a saga, which is the half of the exit criteria no unit test covers.
- **Step 14 — CI.** Two stages on the install walk, a hand-written `FanOutStep` and a hand-written `CompensatableStep` in a real `laravel/laravel` skeleton on every engine. Rehearsing it found a Milestone 8 defect: `laraflow:start` resolved a bare workflow name against the global namespace first, so an `App\Workflows\Broadcast` was refused in favour of Laravel's `Broadcast` facade alias.
- **Step 15 — the audit.** Two hostile reviews, concurrency leading. Two criticals, both the same shape from different directions: the `compensating` status was written durably and then stranded — the claim predicate and cancellation had not been taught it, and an exhausted undo rested the execution nowhere at all. Nine further defects, and five documents that had not followed the code out of `advanced-workflows.md`.
- **Step 16 — closure.** This record, the README section both capabilities had been missing, and `CHANGELOG.md` as 0.5.0.

#### Exit criteria

- ~~A fan-out of a thousand children completes, fails partially, and is cancelled mid-flight under test, on every engine, with every outcome visible from `laraflow:show`.~~ Met: `tests/Feature/Workflow/FanOutScaleTest.php` takes all three at a thousand members, and `SagaInspectionTest::test_the_console_names_what_a_fan_out_is_waiting_on` holds the console to showing it.
- ~~A compensated workflow is replayable from its record: an operator can see what ran, what was undone, and what could not be.~~ Met: `SagaInspectionTest::test_the_console_shows_what_ran_what_was_undone_and_what_could_not_be`, and the same assertions against the dashboard — made true by the decision that a compensation is an ordinary step row, which is why neither needed new rendering.

### Milestone 10 — Schedules, triggers, and the operator's edge ✅

#### Work

- ~~Scheduled workflows: a workflow declares a cron expression and Laraflow starts it durably — exactly one execution per tick across any number of schedulers, with missed ticks a stated policy.~~ Semantics fixed in `docs/schedules.md` before implementation (step 4); shipped in steps 5–9.
- ~~Triggers: an opt-in signal endpoint with signed payloads, so approvals and webhooks reach `signal()` through routes and middleware the package ships rather than every application re-deriving them.~~ Semantics fixed in `docs/triggers.md` before implementation (step 4); shipped in steps 10–13, named provider triggers included.
- ~~Per-tenant isolation and throttling: concurrency and rate ceilings per tenant, enforced at claim time, with the fairness rule documented.~~ Semantics fixed in `docs/tenancy.md` before implementation (step 4); shipped in steps 14–16.
- ~~Operator ergonomics: `laraflow:signal`, `laraflow:children`, bulk cancel and re-run by filter, and structured JSON output on every command for automation.~~ Shipped in steps 17–20; the generators and `laraflow:install` stay human on purpose, being scaffolding rather than operations.
- The leftovers, taken first (steps 1–3): ~~the prose that had not followed the code out of Milestones 7–9, and the runner table that never listed `signal()`~~; ~~the per-tenant rate-limit bucket deferred from Milestone 8, on the ambient `ExecutionScope` that audit said it needed~~; ~~`dragonmantank/cron-expression` declared rather than inherited~~. The release tags 0.3.0–0.5.0 were found never to have been cut, and were cut by hand on the merge commits that converted the changelog.

The three decisions that shape everything downstream, taken in the contracts: a **tick is a row** whose unique `(schedule, instant)` index is the once-per-tick guarantee, committed in one transaction with the execution it starts; a **trigger is a signed `POST`** whose signature binds timestamp, execution, signal, and body, verified before any read, with no environment bypass and no store of seen signatures; and a **tenant ceiling is enforced in `claim()`** and nowhere else, honest about the instant and exact in the steady state, with fairness stated as a sizing rule rather than promised as a cap.

#### How it closed

Twenty-three steps on 3 November 2026, in the order planned at the milestone's start: the leftovers first (the stale prose, the per-tenant rate-limit bucket on the new `ExecutionScope`, the `dragonmantank/cron-expression` dependency declared), the three contracts before any of their code, then schedules (tables, declaration and registry, the scheduler, the two commands, the race proofs), triggers (the mount and the scheme, named provider triggers, the exit criterion over real HTTP with `curl` and `openssl` in the install walk), tenant ceilings (policy and limits, enforcement at the claim, the fairness proof), the operator's edge (the two commands, the bulk forms, `--json` everywhere), and the audit. CI found two things on the way (step 13a): the install walk's dashboard-absence assertion owned every `laraflow.*` route name the moment triggers mounted theirs, and two payload assertions compared JSON-column reads with `assertSame` — MySQL keeps its own key order, the lesson Milestone 8 already recorded. The closing audit (step 22) ran four hostile reviews scoped to the dashboard, Milestone 9, and Milestone 10; its record is in `CHANGELOG.md` under 0.6.0's *Fixed*.

#### Exit criteria

- ~~A scheduled workflow runs once per tick with three schedulers racing.~~ Met: in the suite, two claimants reading one high-water mark where the loser's whole transaction — execution included — rolls back (`ScheduleTest::test_a_losing_claim_rolls_back_the_execution_it_created`), and in CI, three real `laraflow:schedule` processes racing one due tick on every engine with one `started`, one execution, and three zero exits (`.github/actions/install-walk`).
- ~~A signal arrives from an unauthenticated HTTP client and is refused; from a signed one, it resumes the execution.~~ Met: `tests/Feature/Triggers/SignedEndpointTest.php`, and over real HTTP in the install walk, where `openssl` computes the signature the way any non-PHP caller would.
- ~~Two tenants share a worker pool and neither can starve the other under load, by test.~~ Met: `TenantThrottlingTest::test_two_tenants_share_a_pool_and_the_bounded_one_cannot_starve_the_other`, where the bound is asserted as *equality* across a tenfold flood, on every engine.

### Milestone 11 — Ecosystem, observability, and documentation

Released as **0.7.0**, the last minor before 1.0 ([decision 0009](docs/decisions/0009-one-release-per-milestone.md)). Planned November 2026; the contracts below were written first (step 0).

#### Work

Narrowed after step 6 to what only a Laravel package should do: emit what an application needs to observe it, and leave the monitoring itself to the application ([decision 0011](docs/decisions/0011-one-optional-package.md)). Metrics bridges for Prometheus, StatsD, and Pulse, and the core metrics model they shared, were built in steps 2–5 and removed before release.

0. **Contracts first.** ✅ [docs/metrics.md](docs/metrics.md), [docs/tracing.md](docs/tracing.md), [docs/community-connectors.md](docs/community-connectors.md), and [docs/decisions/](docs/decisions/README.md).
1. **The metrics snapshot.** ✅ `Snapshots` and `MetricsSnapshot`: exact counts of what is unfinished, due, overdue, deferred, and stalled, in a fixed number of queries; a tenant id on the connector events; and the scoping fix in `laraflow:summary` it surfaced. Metrics themselves are the application's, from the events and the snapshot, with documented recipes.
2. **Bridge scaffolding.** Built, then removed before release ([decision 0011](docs/decisions/0011-one-optional-package.md)).
3. **`laraflow/prometheus`.** Built, then removed before release (decision 0011).
4. **`laraflow/statsd`.** Built, then removed before release (decision 0011).
5. **`laraflow/pulse`.** Built, then removed before release (decision 0011).
6. **Tracing.** ✅ The `Tracer` hook and the trace context each execution remembers, in the core; `laraflow/opentelemetry`, the one optional package, depending on the OpenTelemetry API only.
7. **The documentation site.** ✅ VitePress over `docs/` ([decision 0008](docs/decisions/0008-documentation-site-on-vitepress.md)), one version: a CI build that fails on a dead link, deployed to Pages from `main` once the maintainer enables it.
8. **The documentation itself.** A short README; guides built from today's README sections; a guided tutorial; one upgrade guide per minor.
9. **Contributing a connector.** A documentation page and the community list: a connector is a package that runs `ConnectorContract` in its own CI, and is listed by pull request. No template repository or reusable workflow.
10. **Open-source practice.** Issue forms with security reports routed to SECURITY.md, a maintainers file, the release cadence and support window on one page. Discussions, Pages, Packagist, and tag signing are maintainer actions.
11. **Horizon and Octane.** Horizon: a documentation section on matching its `timeout` to `stale_after`, no code. Octane: tests that no per-request state — the dashboard's callbacks, the log context — leaks between requests, and code only if one does.
12. **Audit and release.** A hostile audit of the milestone; CI run by the maintainer, its failures addressed from the logs; the closing record, and 0.7.0.

Everything new in this milestone — the snapshot, the tracing hook, the span names and attributes — is Experimental until 1.0, so real use decides what is frozen.

#### Exit criteria

- A new contributor can publish a connector following only the published docs, and the contract test is what reviews it.
- The documentation site builds from `docs/` with no dead link, and the README fits on a screen or two.
- Installing nothing optional changes nothing: the core records no metrics and no traces by itself.

### Milestone 12 — Production validation and 1.0

#### Work

- A soak and benchmark suite: sustained load on every engine with worker loss, queue loss, and database failover injected, run on a schedule and published.
- An external security review of the claim discipline, the signal boundary, and the connector credential paths, with findings closed or recorded.
- The 1.0 API freeze: every Public class reviewed line by line, deprecation attributes and a `laraflow:upgrade` checker shipped, the compatibility policy switched to its post-1.0 rules, and a long-term-support window declared.
- 1.0.0 released by the same machinery as every release before it, drafted by CI and published by a person.

#### Exit criteria

- The soak suite has run clean for a full support window with no recovery defect found.
- A 0.x application upgrades to 1.0 by `composer require` and `php artisan migrate`, with the upgrade checker naming every change it must make first.
- The README's status line says what a user can rely on, and the contract tests make it true.

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
10. ~~Release automation and the schema freeze.~~ Done.
11. ~~Close the Milestone 6 audit debt, then conditions, waits, and sub-workflows — semantics first, capability second, audit last.~~ Done. Milestone 7 is complete and the MVP is released as 0.2.0.
12. ~~The deferred audit items first, then workflow versioning and the idempotency store, then the generators and the install story (Milestone 8).~~ Done.
13. ~~The inspection dashboard, over the contract the console already proves (Milestone 8.5).~~ Done.
14. ~~Fan-out/fan-in, then compensation — semantics first (Milestone 9).~~ Done, and released as 0.5.0.
15. ~~Schedules, signed triggers, tenant throttling (Milestone 10).~~ Done, and released as 0.6.0.
16. Contracts, the metrics snapshot and the tracing hook, the documentation site, contributing connectors, open-source practice (Milestone 11, 0.7.0).
17. Soak, external review, the API freeze, 1.0 (Milestone 12, 1.0.0).

## Out of scope through 1.0

- Claiming exactly-once execution for arbitrary side effects; the idempotency store makes at-least-once safe, it does not make it exactly-once.
- A built-in visual workflow designer or hosted control plane; the dashboard of Milestone 8.5 inspects, it does not author. **Authoring is a post-1.0 question, not a dropped one**: a workflow is a PHP class with a persisted, validated plan, and anything that writes one has to answer to the same plan validation and versioning rules as the generators do. That is a design problem worth taking once the execution contracts are frozen and have survived production, not before.
- Supporting every Laravel version/database/queue backend without CI evidence.
- A large first-party catalog of connectors; the ecosystem and the contract test are the catalog.
- Arbitrary graph execution beyond fan-out/fan-in; the plan stays a persisted, validated structure.
