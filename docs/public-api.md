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
| `Laraflow\Workflow\WorkflowContext` | Public | Key/value data shared between steps in one execution. `get`, `set`, `has`. Will gain read access to execution metadata (id, attempt) in Milestone 1. |

## Execution

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\WorkflowRunner` | Public | Entry point for running workflows. Method set is changing; see [Runner method naming](#runner-method-naming). |
| `Laraflow\Workflow\WorkflowRunner::run()` | Public | Synchronous. Return type changes to `WorkflowExecution` in Milestone 1. |
| `Laraflow\Workflow\WorkflowRunner::resume()` | Experimental | Incomplete and unsafe for recovery. Replaced by `execute()` in Milestone 1. |
| `Laraflow\Workflow\WorkflowExecution` | Public | Read-only handle describing one execution: id, workflow class, input, status, timestamps. |
| `Laraflow\Workflow\StepExecution` | Public | Read-only handle describing one step within an execution. |
| `Laraflow\Workflow\ExecutionStatus` | Public | Enum: `pending`, `running`, `completed`, `failed`. More cases (e.g. `cancelled`) may be added; applications should handle unknown cases defensively. |
| `Laraflow\Workflow\StepStatus` | Public | Enum: `pending`, `running`, `completed`, `failed`. Same caveat. |

Public properties on `WorkflowExecution` and `StepExecution` are currently writable so the runner can mutate them. Treat them as read-only from application code; they will become read-only when the runner moves to explicit transition methods.

## Persistence

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\ExecutionRepository` | Public (contract) | The supported way to read executions. Implementing it yourself is possible but the method set will grow in Milestone 1 (claiming, checkpointing). |
| `Laraflow\Workflow\Repositories\EloquentExecutionRepository` | Internal | Default binding. Swap via the container if needed. |
| `Laraflow\Workflow\Models\WorkflowExecutionModel` | Internal | Table layout will change. |
| `Laraflow\Workflow\Models\StepExecutionModel` | Internal | Table layout will change. |
| Database tables `laraflow_workflow_executions`, `laraflow_step_executions` | Internal | Do not write to them. Reading for debugging is fine; columns are not frozen. |

## Integrations

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Core\LaraflowManager` | Public | `register(name, factory)` and `connector(name)`. |
| `Laraflow\Integration\Connector` | Public (contract) | `name()` and `request(ConnectorRequest): ConnectorResponse`. |
| `Laraflow\Integration\ConnectorRequest` | Public | Value object: method, uri, options. |
| `Laraflow\Integration\ConnectorResponse` | Public | Value object: status, headers, body, `successful()`, `failed()`. |
| `Laraflow\Auth\Authenticator` | Public (contract) | `authenticate(array $options): array`. |
| `Laraflow\Auth\GithubTokenAuthenticator` | Public | Bearer-token authenticator. |
| `Laraflow\Integration\Github\GithubConnector` | Experimental | Prototype; HTTP behaviour not yet configurable. |
| `Laraflow\Integration\ConnectorFactory` | Internal | Used by `LaraflowManager`. |
| `Laraflow\Integration\ConnectorManager` | Internal | Instance cache used by `LaraflowManager`. |

## Exceptions

| Class | Level |
|-------|-------|
| `Laraflow\Exceptions\LaraflowException` | Public. Base class for all package exceptions. |
| `Laraflow\Exceptions\ConnectorException` | Public. |
| `Laraflow\Exceptions\TransportException` | Public. Thrown when a connector cannot reach its service. |

Step exceptions are *not* wrapped: `run()` rethrows whatever the step threw after persisting the failure.

## Configuration and service provider

| Item | Level | Notes |
|------|-------|-------|
| `Laraflow\Core\LaraflowServiceProvider` | Public | Auto-discovered. |
| `config/laraflow.php` keys `migrations.load`, `integrations.github.token` | Public | |
| Publish tags `laraflow-config`, `laraflow-migrations` | Public | |

## Runner method naming

The roadmap asked for a decision on `run`, `start`, `execute`, and `resume` before applications depend on the synchronous API. The decision, to be implemented in Milestone 1:

| Method | Behaviour | Returns |
|--------|-----------|---------|
| `start(Workflow $workflow, array $input = [])` | Create the execution record and the full step plan in `pending` state. Does **not** run anything. | `WorkflowExecution` |
| `execute(int\|string $executionId, Workflow $workflow)` | Claim an existing execution, rebuild state from durable records, run remaining steps. Used for both first execution and recovery. | `WorkflowExecution` |
| `run(Workflow $workflow, array $input = [])` | Convenience: `start()` then `execute()` in the same process. | `WorkflowExecution` |
| `resume(...)` | **Removed.** Its job is `execute()`. | — |

### Why `run()` returns the execution handle rather than the context

Today `run()` returns the `WorkflowContext`. It will return a `WorkflowExecution` instead, for these reasons:

1. **The context is in-process plumbing, not a result.** It is the scratchpad steps use to talk to each other. Returning it hands callers an object with no identity, no status, and no timestamps, and couples them to how steps happen to pass data around.
2. **The handle is what exists across processes.** An execution has an id you can store, display, poll, resume, or hand to a queue worker. That is what a caller needs after `run()` returns, especially when the result is `failed`.
3. **Sync and async paths stay symmetric.** `start()`, `execute()`, and `run()` all return the same type. Code written against the synchronous `run()` does not change shape when the workflow moves onto a queue in Milestone 2.
4. **Nothing is lost.** Milestone 1 persists the context as part of each checkpoint, so completed context and step outputs are readable from the handle (or via the repository) rather than only from a live object.

Failure behaviour is unchanged: `run()` and `execute()` persist the failure, then rethrow the step's exception. Applications that prefer a non-throwing API can catch and then inspect the execution via the repository.

## Serialisation contract (preview)

Milestone 1 formalises this; it is stated here because it already constrains step authors:

- Workflow input, step input, step output, and context values **must be JSON-serialisable**: `null`, booleans, integers, floats, strings, and arrays of those.
- Live PHP objects, closures, resources, and Eloquent models are not durable values. Store identifiers and reload.
- Violations will raise a package exception at the persistence boundary rather than being silently coerced.
