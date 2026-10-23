# Public API and stability

This document records which parts of Laraflow applications may depend on, which are experimental, and which are internal. It also records API decisions made before the first release so that they are deliberate rather than accidental.

Laraflow is pre-release. "Public" here means *intended to become stable*, not *stable today*. Breaking changes to public classes are allowed before `1.0` but must be listed in `CHANGELOG.md`.

## Stability levels

| Level | Meaning | Change policy before 1.0 |
|-------|---------|--------------------------|
| **Public** | Intended for application code. Documented in the README. | Breaking changes announced in the changelog with migration notes. |
| **Experimental** | Shipped so the design can be evaluated. Behaviour is incomplete or unproven. | May change or be removed without notice. Marked with `@experimental` in docblocks. |
| **Internal** | Implementation detail. Not covered by any compatibility promise. | May change at any time. Marked with `@internal` in docblocks where practical. |

## Workflow definition

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\Workflow` | Public | Abstract base. `steps(): array` returns an ordered list of step class names. |
| `Laraflow\Workflow\Step` | Public | Interface. `handle(WorkflowContext $context): mixed`. The return value is persisted as the step output and must be JSON-serialisable. |
| `Laraflow\Workflow\WorkflowContext` | Public | Key/value data shared between steps in one execution: `get`, `set`, `has`, `forget`, `all`. A stored `null` is a value (`has()` is true, `get()` returns null, not the default). Exposes execution metadata for idempotency: `executionId`, `stepPosition`, `attempt`, `idempotencyKey`. |

## Execution

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\WorkflowRunner` | Public | Entry point for running workflows: `start()`, `execute()`, `run()`. Resolve it from the container; its constructor (repository, container, PSR-3 logger, stale/error settings) is internal wiring. Behaviour is specified in [docs/execution-lifecycle.md](execution-lifecycle.md). |
| `Laraflow\Workflow\WorkflowExecution` | Public | Read-only handle describing one execution: id, workflow class, input, context, status, error, timestamps. State changes go through `mark*()` transition methods that enforce the state machine. |
| `Laraflow\Workflow\StepExecution` | Public | Read-only handle describing one step within an execution, with the same transition-method rule. |
| `Laraflow\Workflow\ExecutionStatus` | Public | Enum: `pending`, `running`, `completed`, `failed`. More cases (e.g. `cancelled`) may be added; applications should handle unknown cases defensively. |
| `Laraflow\Workflow\StepStatus` | Public | Enum: `pending`, `running`, `completed`, `failed`. Same caveat. |

## Queue execution

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Queue\WorkflowDispatcher` | Public | Asynchronous counterpart of `WorkflowRunner::run()`: `dispatch(Workflow, array $input, ?string $connection, ?string $queue)` and `dispatchExecution(id, ...)`. Behaviour specified in [docs/queue-and-recovery.md](queue-and-recovery.md). |
| `Laraflow\Queue\ExecuteWorkflowJob` | Public | The queued job. Public so applications can reference it in `Queue::fake()` assertions, Horizon configuration, and worker tuning. Its constructor takes an execution id; the payload carries nothing else. |
| `Laraflow\Workflow\RecoveryService` | Internal | Engine behind `laraflow:recover`. Use the command. |
| `Laraflow\Workflow\RecoveryCandidate` | Internal | Value object returned by `RecoveryService::scan()`. |
| `Laraflow\Workflow\WorkflowResolver` | Internal | Resolves the workflow class recorded on an execution. |
| `Laraflow\Events\EventEmitter` | Internal | Applies the observational-event policy below. |

## Events

All events live in `Laraflow\Events`, are `readonly`, and are **public**: `WorkflowExecutionDispatched`, `WorkflowExecutionStarted`, `WorkflowStepStarted`, `WorkflowStepCompleted`, `WorkflowStepFailed`, `WorkflowExecutionCompleted`, `WorkflowExecutionFailed`, `WorkflowExecutionRetryScheduled`.

Guarantees applications may rely on:

- Each event fires **after** the durable write it describes; if persisting a failure fails, no failure event fires.
- Events carry identifiers and metadata only — **never workflow input, step output, or context**. Failure events carry the exception class and (unless `laraflow.events.include_error_messages` is false) the truncated message; never the exception object or stack trace.
- A listener that throws is logged and swallowed — it cannot change execution state, abort a dispatch, or mask a step's exception. Use queued listeners when failure must be retried or surfaced.
- `Started`, `StepStarted`, `StepFailed`, and `ExecutionFailed` may fire more than once per execution (recovery); `ExecutionCompleted` fires once.

New event classes and new properties on existing ones may be added before 1.0; listeners should not depend on an exhaustive list.

Public properties on `WorkflowExecution` and `StepExecution` remain technically writable (PHP 8.2 has no asymmetric visibility), but writing them from application code is unsupported: all state changes must go through the `mark*()` transition methods, which throw `InvalidStateTransitionException` for anything the state machine forbids.

## Persistence

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\ExecutionRepository` | Public (contract) | The supported way to read executions, and the persistence boundary: `create` (with plan snapshot), `find`, `claim` (compare-and-set), `steps`, `saveStep`, `checkpoint` (transactional, claim-verified), `release`, plus the recovery scans `stale` and `pendingBefore`. Custom implementations must honour the guarantees in [docs/execution-lifecycle.md](execution-lifecycle.md). |
| `Laraflow\Workflow\Repositories\EloquentExecutionRepository` | Internal | Default binding. Swap via the container if needed. |
| `Laraflow\Workflow\Models\WorkflowExecutionModel` | Internal | Table layout will change. |
| `Laraflow\Workflow\Models\StepExecutionModel` | Internal | Table layout will change. |
| Database tables `laraflow_workflow_executions`, `laraflow_step_executions` | Internal | Do not write to them. Reading for debugging is fine; columns are not frozen. |

## Integrations

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Core\LaraflowManager` | Public | `register(name, factory)`, `connector(name)`, `has(name)`. Factories run lazily, once; instances are cached for the application lifetime. |
| `Laraflow\Integration\Connector` | Public (contract) | `name()` and `request(ConnectorRequest): ConnectorResponse`. |
| `Laraflow\Integration\ConnectorRequest` | Public | Immutable (`readonly`) value object: method, uri, options. |
| `Laraflow\Integration\ConnectorResponse` | Public | Immutable (`readonly`) value object: status, headers, body, `successful()` (2xx), `failed()`. |
| `Laraflow\Auth\Authenticator` | Public (contract) | `authenticate(array $options): array`. |
| `Laraflow\Auth\GithubTokenAuthenticator` | Public | Bearer-token authenticator. Rejects an empty token at construction with `ConnectorException`. |
| `Laraflow\Integration\Github\GithubConnector` | Experimental | Prototype; HTTP behaviour not yet configurable. Takes an `Authenticator` and Laravel's HTTP client factory. |
| `Laraflow\Integration\ConnectorFactory` | Internal | Used by `LaraflowManager`. |
| `Laraflow\Integration\ConnectorManager` | Internal | Instance cache used by `LaraflowManager`. |

## Exceptions

| Class | Level |
|-------|-------|
| `Laraflow\Exceptions\LaraflowException` | Public. Base class for all package exceptions. |
| `Laraflow\Exceptions\ConnectorException` | Public. Base class for connector exceptions; also thrown directly for configuration problems such as a missing token. |
| `Laraflow\Exceptions\ConnectorNotRegisteredException` | Public. Unknown connector name. |
| `Laraflow\Exceptions\InvalidConnectorException` | Public. A connector factory returned something that is not a `Connector`. |
| `Laraflow\Exceptions\TransportException` | Public. Thrown when a connector cannot reach its service. Never includes request options (they carry credentials). |
| `Laraflow\Exceptions\Workflow\WorkflowException` | Public. Base class for workflow/lifecycle exceptions. |
| `Laraflow\Exceptions\Workflow\ExecutionNotFoundException` | Public. Unknown execution id. |
| `Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException` | Public. Another worker holds a fresh claim. |
| `Laraflow\Exceptions\Workflow\ExecutionNotRunnableException` | Public. Execution is in a terminal status. |
| `Laraflow\Exceptions\Workflow\ClaimLostException` | Public. This worker's claim was taken over; nothing was persisted. |
| `Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException` | Public. `start()` rejected the definition: no steps, or a step that is not a class implementing `Step`. Nothing is persisted. |
| `Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException` | Public. Supplied workflow does not match the persisted plan. |
| `Laraflow\Exceptions\Workflow\InvalidStateTransitionException` | Public. Forbidden execution/step state transition. |
| `Laraflow\Exceptions\Workflow\UnserializableValueException` | Public. Input/output/context value violates the serialization contract. |

Step exceptions are *not* wrapped: `run()` rethrows whatever the step threw after persisting the failure.

## Configuration and service provider

| Item | Level | Notes |
|------|-------|-------|
| `Laraflow\Core\LaraflowServiceProvider` | Public | Auto-discovered. |
| `config/laraflow.php` keys `migrations.load`, `execution.stale_after`, `execution.max_error_length`, `integrations.github.token` | Public | |
| `config/laraflow.php` keys `queue.*` (`connection`, `queue`, `tries`, `backoff`, `timeout`, `after_commit`), `recovery.*` (`limit`, `max_attempts`, `pending_after`), `events.include_error_messages` | Public | Defaults and their rationale in [docs/queue-and-recovery.md](queue-and-recovery.md). `queue.tries` defaults to 1 and `recovery.pending_after` defaults to null (pending scan disabled) deliberately. |
| Publish tags `laraflow-config`, `laraflow-migrations` | Public | |

## Console commands

All experimental: names, signatures, and output format may change while the operational tooling (roadmap Milestones 2 and 4) takes shape.

| Command | Level | Notes |
|---------|-------|-------|
| `laraflow:demo {--fail} {--queue}` | Experimental | Runs the bundled `Laraflow\Demo\DemoWorkflow`. `--fail` makes the payment step fail on attempt 1 so recovery can be demonstrated; `--queue` dispatches it instead of running it inline. |
| `laraflow:list {--status=} {--limit=20}` | Experimental | Lists executions, newest first. |
| `laraflow:show {execution}` | Experimental | One execution in detail: steps, attempts, outputs, errors, context, claim. |
| `laraflow:execute {execution} {--queue}` | Experimental | Executes or recovers an execution using the workflow class stored on it; refuses if that class no longer exists or is not a `Workflow`. `--queue` hands it to a worker instead. No attempt bound — this is the deliberate operator override. |
| `laraflow:recover {--stale-after=} {--pending-after=} {--limit=} {--max-attempts=} {--workflow=} {--dry-run} {--sync}` | Experimental | Finds interrupted executions and retries them within a bounded policy. Exits non-zero when work was abandoned, unrecoverable (workflow class missing), or an action failed. Intended for the scheduler; see [docs/queue-and-recovery.md](queue-and-recovery.md#recovering-interrupted-work). |

The `Laraflow\Demo` classes exist for the demo command and are experimental; do not build applications on them.

## Runner method naming

The roadmap asked for a decision on `run`, `start`, `execute`, and `resume` before applications depend on the synchronous API. The decision, implemented in Milestone 1:

| Method | Behaviour | Returns |
|--------|-----------|---------|
| `start(Workflow $workflow, array $input = [])` | Create the execution record and the full step plan in `pending` state. Does **not** run anything. | `WorkflowExecution` |
| `execute(int\|string $executionId, Workflow $workflow)` | Claim an existing execution, rebuild state from durable records, run remaining steps. Used for both first execution and recovery. | `WorkflowExecution` |
| `run(Workflow $workflow, array $input = [])` | Convenience: `start()` then `execute()` in the same process. | `WorkflowExecution` |
| `resume(...)` | **Removed** in Milestone 1. Its job is `execute()`. | — |

### Why `run()` returns the execution handle rather than the context

`run()` originally returned the `WorkflowContext`; it now returns a `WorkflowExecution`, for these reasons:

1. **The context is in-process plumbing, not a result.** It is the scratchpad steps use to talk to each other. Returning it hands callers an object with no identity, no status, and no timestamps, and couples them to how steps happen to pass data around.
2. **The handle is what exists across processes.** An execution has an id you can store, display, poll, resume, or hand to a queue worker. That is what a caller needs after `run()` returns, especially when the result is `failed`.
3. **Sync and async paths stay symmetric.** `start()`, `execute()`, and `run()` all return the same type. Code written against the synchronous `run()` does not change shape when the workflow moves onto a queue in Milestone 2.
4. **Nothing is lost.** The context is persisted at each checkpoint, so completed context and step outputs are readable from the handle (`$execution->context`) or via the repository rather than only from a live object.

Failure behaviour is unchanged: `run()` and `execute()` persist the failure, then rethrow the step's exception. Applications that prefer a non-throwing API can catch and then inspect the execution via the repository.

## Serialisation contract

Enforced since Milestone 1 (see [docs/execution-lifecycle.md](execution-lifecycle.md#serialization-contract)):

- Workflow input, step output, and context values **must be JSON-serialisable**: `null`, booleans, integers, finite floats, strings, and arrays of those.
- Live PHP objects, closures, resources, and Eloquent models are not durable values. Store identifiers and reload.
- Violations raise `UnserializableValueException` at the persistence boundary — naming the offending key — rather than being silently coerced.
