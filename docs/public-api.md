# Public API and stability

This document records which parts of Laraflow applications may depend on, which are experimental, and which are internal. It also records API decisions made before the first release so that they are deliberate rather than accidental.

Laraflow is pre-release. "Public" here means *intended to become stable*, not *stable today*. Breaking changes to public classes are allowed before `1.0` but must be listed in `CHANGELOG.md`.

## Stability levels

| Level | Meaning | Change policy before 1.0 |
|-------|---------|--------------------------|
| **Public** | Intended for application code. Documented in the README. | Breaking changes announced in the changelog with migration notes. |
| **Experimental** | Shipped so the design can be evaluated. Behaviour is incomplete or unproven. | May change or be removed without notice. Marked with `@experimental` in docblocks. |
| **Internal** | Implementation detail. Not covered by any compatibility promise. | May change at any time. Marked with `@internal` in docblocks. |

Two tests keep this document honest in both directions: every class in `src/` must either appear here or carry an `@internal`/`@experimental` marker, and every class this document names must exist with the stability level its docblock claims (`tests/Feature/ProjectContractsTest.php`). Retiring something **Public** follows the deprecation path in [compatibility.md](compatibility.md#how-a-deprecation-works): `@deprecated` naming the replacement, a changelog *Deprecated* entry with the planned removal, then removal no sooner than the policy allows.

## Workflow definition

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\Workflow` | Public | Abstract base. `steps(): array` returns an ordered list of step class names. Optional overrides: `defaultStepPolicy(): ?StepPolicy` and `failurePolicy(): FailurePolicy`. |
| `Laraflow\Workflow\Step` | Public | Interface. `handle(WorkflowContext $context): mixed`. The return value is persisted as the step output and must be JSON-serialisable. |
| `Laraflow\Workflow\ConditionalStep` | Public | Optional interface for a step: `shouldRun(WorkflowContext): bool`, consulted at the start of every attempt. False durably skips the step — attempt-free, context-neutral, replayed from the record on recovery. Conditions must be pure functions of the context; see [docs/advanced-workflows.md](advanced-workflows.md). |
| `Laraflow\Workflow\SubWorkflowStep` | Public | Base class for a step that runs another workflow as a child execution: implement `workflow(WorkflowContext)` and optionally `input(WorkflowContext)`; `handle()` is final. The child is linked by `parent_execution_id`/`parent_step_execution_id`, inherits correlation and tenant identifiers, and its completed/failed/cancelled outcome becomes the step's output or `ChildWorkflowFailedException`; retrying the step resumes the *same* child. Nesting is bounded by `laraflow.workflows.max_depth`. See [docs/advanced-workflows.md](advanced-workflows.md). |
| `Laraflow\Workflow\ChildWorkflows` | Internal | The machinery behind `SubWorkflowStep`: create-or-reuse, depth bound, delivery, park. Injected into `SubWorkflowStep` constructors by the container. |
| `Laraflow\Workflow\ChildDelivery` | Public (contract) | How the workflow layer hands an execution to whatever runs executions asynchronously; the default binding adapts `WorkflowDispatcher`, and the sync queue driver makes delivery run in-process. Replace via the container to route child executions elsewhere. |
| `Laraflow\Workflow\ParentNotifier` | Internal | Signals a waiting parent that its child reached a resting outcome. Every refusal is a log line; the parent's heartbeat heals a lost signal. |
| `Laraflow\Workflow\Wait` | Public | Returned from `Step::handle()` to park the execution: `until()` / `untilSeconds()` (timed), `forSignal()` with optional `timeoutAfterSeconds()` (signal). A wait is the step's outcome boundary — resuming completes the step with the signal payload (or null) as output; `handle()` never resumes mid-body. Signal names must not start with the reserved `laraflow.` prefix. See [docs/advanced-workflows.md](advanced-workflows.md). |
| `Laraflow\Workflow\WorkflowContext` | Public | Key/value data shared between steps in one execution: `get`, `set`, `has`, `forget`, `all`. A stored `null` is a value (`has()` is true, `get()` returns null, not the default). Exposes execution metadata for idempotency — `executionId`, `stepPosition`, `attempt`, `idempotencyKey` — the current attempt's deadline (`deadline()`, `remainingSeconds()`), and the execution's identifiers (`correlationId()`, `tenantId()`), read from durable state so a recovered step sees the originals. |
| `Laraflow\Workflow\Policy\StepPolicy` | Public | Retry and timeout policy for one step: attempts, capped exponential backoff, deterministic jitter, `retryOn`/`dontRetryOn`, `timeoutSeconds`. Specified in [retry-and-failure-policy.md](retry-and-failure-policy.md). |
| `Laraflow\Workflow\Policy\HasStepPolicy` | Public (contract) | Optional interface for steps declaring their own `StepPolicy`. |
| `Laraflow\Workflow\Policy\FailurePolicy` | Public | Enum: `stop` (default), `continue`. |
| `Laraflow\Workflow\Policy\PolicyResolver` | Internal | Applies the step → workflow → config precedence. |

## Execution

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\WorkflowRunner` | Public | Entry point for running workflows: `start()`, `execute()` (with the `ignoreRetrySchedule` override, which also resumes a *timed* wait early), `run()`, `cancel()`, and `signal()` (records a signal for a waiting execution; recording is not delivery). `start()` and `run()` take an optional trailing `ExecutionOptions`. Resolve it from the container; its constructor is internal wiring. Behaviour is specified in [docs/execution-lifecycle.md](execution-lifecycle.md) and [docs/retry-and-failure-policy.md](retry-and-failure-policy.md). |
| `Laraflow\Workflow\ExecutionOptions` | Public | Identifiers recorded on an execution at creation: `correlationId`, `tenantId`. Validated at construction — trimmed, blank treated as absent, rejected over 255 characters or containing control characters. An options object rather than extra parameters so later additions do not change four signatures again. |
| `Laraflow\Workflow\WorkflowExecution` | Public | Read-only handle describing one execution: id, workflow class, input, context, status, error, timestamps, `correlationId`, `tenantId`, and `finishedAt` (set by every terminal transition, cleared when the execution runs again). State changes go through `mark*()` transition methods that enforce the state machine. |
| `Laraflow\Workflow\StepExecution` | Public | Read-only handle describing one step within an execution, with the same transition-method rule. |
| `Laraflow\Workflow\ExecutionStatus` | Public | Enum: `pending`, `running`, `completed`, `failed`, `retrying`, `waiting`, `cancelled`, plus `isFinished()` — true for `completed`, `failed`, and `cancelled`, the states that record `finished_at`. Deliberately not named `isTerminal()`: "terminal" means *never claimable* (`completed`, `cancelled`) and `failed` is re-executable, so the two questions have different answers. More cases may be added; applications should handle unknown cases defensively. |
| `Laraflow\Workflow\StepStatus` | Public | Enum: `pending`, `running`, `completed`, `failed`, `retrying`, `skipped`, `waiting`. Same caveat. |
| `Laraflow\Workflow\StepAttempt` | Public | Read-only record of one finished attempt: number, outcome, error, timestamps, `durationMs()`, and any retry it scheduled. |
| `Laraflow\Workflow\StepAttemptStatus` | Public | Enum: `completed`, `failed`, `timed_out`, `interrupted`. |
| `Laraflow\Support\ErrorRedactor` | Public (contract) | Rewrites exception messages before Laraflow persists, logs, or emits them; configured via `laraflow.errors.redactor`. |
| `Laraflow\Support\LoggingContext` | Internal | Publishes `laraflow.*` keys into Laravel's log context for the duration of an execution — and `laraflow.connector`/`laraflow.connection` for the duration of one connector request — restoring them afterwards. Controlled by `laraflow.logging.context`. |
| `Laraflow\Events\LifecycleLogger` | Internal | Wildcard listener writing one log record per lifecycle event. Controlled by `laraflow.logging.lifecycle`. |
| `Laraflow\Support\Duration` | Internal | Wall-clock milliseconds between two instants, shared by `StepAttempt` and `WorkflowExecution`. |
| `Laraflow\Support\Clock` | Internal | Default PSR-20 `ClockInterface` binding, backed by Laravel's `Date` facade. Replace the binding to control every Laraflow timestamp. |

## Queue execution

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Queue\WorkflowDispatcher` | Public | Asynchronous counterpart of `WorkflowRunner::run()`: `dispatch(Workflow, array $input, ?string $connection, ?string $queue, ?ExecutionOptions $options)` and `dispatchExecution(id, ...)`. Behaviour specified in [docs/queue-and-recovery.md](queue-and-recovery.md). |
| `Laraflow\Queue\ExecuteWorkflowJob` | Public | The queued job. Public so applications can reference it in `Queue::fake()` assertions, Horizon configuration, and worker tuning. Its constructor takes an execution id; the payload carries nothing else. |
| `Laraflow\Workflow\RecoveryService` | Internal | Engine behind `laraflow:recover`. Use the command. |
| `Laraflow\Workflow\RetentionPolicy` | Public | How long finished executions are kept and in what form: `scrubAfterDays`, `pruneAttemptsAfterDays`, `deleteAfterDays`, `deleteFailedAfterDays`, plus `limit`/`chunk`. Every bound is null by default. Validated at construction; `warnings()` reports valid-but-probably-wrong combinations. |
| `Laraflow\Workflow\RetentionTier` | Public | Enum: `scrub_payloads`, `prune_attempts`, `delete`. |
| `Laraflow\Workflow\RetentionService` | Internal | Engine behind `laraflow:prune`. Use the command. |
| `Laraflow\Workflow\RetentionRun` | Internal | State carried between a retention run's tiers: policy, clock instant, scope, accumulating report, and the executions the delete tier accounted for. |
| `Laraflow\Workflow\RetentionReport` | Internal | Counts returned by one retention run. |
| `Laraflow\Workflow\RecoveryCandidate` | Internal | Value object returned by `RecoveryService::scan()`. |
| `Laraflow\Workflow\WorkflowResolver` | Internal | Resolves the workflow class recorded on an execution. |
| `Laraflow\Workflow\RunnerOptions` | Internal | The configured numbers and flags `WorkflowRunner` reads (`staleAfterSeconds`, `maxErrorLength`, `includeErrorMessages`, `maxPayloadBytes`, `maxSuggestedRetryDelaySeconds`), built from config by `fromConfig()`. |
| `Laraflow\Workflow\OutcomeRecorder` | Internal | Persists and announces how a step or execution ended — scheduled retry, step failure, execution failure — and owns the redact-then-truncate chokepoint for every error message Laraflow retains. |
| `Laraflow\Workflow\StepResolver` | Internal | Validates a workflow's step list, checks a persisted plan against the definition it is run with, and builds steps from the container. |
| `Laraflow\Events\EventEmitter` | Internal | Applies the observational-event policy below. |

## Events

All events live in `Laraflow\Events`, are `readonly`, and are **public**: `WorkflowExecutionDispatched`, `WorkflowExecutionStarted`, `WorkflowStepStarted`, `WorkflowStepCompleted`, `WorkflowStepSkipped`, `WorkflowStepWaiting`, `WorkflowStepFailed`, `WorkflowStepRetryScheduled`, `WorkflowExecutionSignalled`, `WorkflowExecutionCompleted`, `WorkflowExecutionFailed`, `WorkflowExecutionCancelled`, `WorkflowExecutionRetryScheduled`, plus the two connector events below.

### Connector events

`ConnectorRequestSent` fires for every response a provider gave, success or error: `connector`, `connection`, `method`, `path` (**no query string** — it may carry a credential), `status`, `successful` (2xx *and* no mapped error), `durationMs` (the whole call, transport retries and throttle waits included), `attempts` (wire attempts). `ConnectorRequestFailed` fires when no response was obtained at all — a connection failure, or Laraflow's own throttle refusing to send — with `errorClass` and `error` (redacted, and omitted entirely when `events.include_error_messages` is false).

They carry **no** request options, headers, or bodies. They deliberately carry no correlation or tenant id: a connector may be called outside any workflow, and inside one Laravel's log context already has them — read `Context::get('laraflow.correlation_id')` in the listener. Note that paging a large result set fires one `ConnectorRequestSent` per page.

The thirteen `Workflow*` lifecycle events, by contrast, each carry `correlationId` and `tenantId` (null when the application supplied none), so a listener can route or tag by them without re-reading the execution.

`WorkflowStepCompleted` and `WorkflowStepFailed` carry `durationMs`, the attempt's own wall-clock time — the same value written to the attempt history, so telemetry and history cannot disagree. `WorkflowExecutionCompleted`, `WorkflowExecutionFailed`, and `WorkflowExecutionCancelled` carry `durationMs` spanning the execution's **first** start to this finish: end-to-end latency including any time spent failed or waiting out a backoff, not compute time. It is null when the execution never started. `WorkflowExecutionStarted` additionally carries `queueLagSeconds`: how long the execution waited for a worker, measured from when it was made *available* (not dispatched — a delayed retry's backoff is not queue wait) to this claim; null when it was never dispatched or was claimed ahead of availability. See [queue-and-recovery.md](queue-and-recovery.md#queue-lag).

Guarantees applications may rely on:

- Each event fires **after** the durable write it describes; if persisting a failure fails, no failure event fires.
- Events carry identifiers and metadata only — **never workflow input, step output, or context**. Failure events carry the exception class and (unless `laraflow.events.include_error_messages` is false) the truncated message; never the exception object or stack trace.
- A listener that throws is logged and swallowed — it cannot change execution state, abort a dispatch, or mask a step's exception. Use queued listeners when failure must be retried or surfaced.
- `Started`, `StepStarted`, `StepFailed`, `StepRetryScheduled`, and `ExecutionFailed` may fire more than once per execution (retries and recovery); `attempt` distinguishes them. `ExecutionCompleted` and `ExecutionCancelled` fire once.
- `ExecutionFailed` fires **only on terminal failure**, never when a retry was scheduled — so alerting built on it stays meaningful once retries are enabled.
- When `laraflow.errors.redactor` is configured, every event message is the redacted form.

New event classes and new properties on existing ones may be added before 1.0; listeners should not depend on an exhaustive list.

Public properties on `WorkflowExecution` and `StepExecution` remain technically writable (PHP 8.2 has no asymmetric visibility), but writing them from application code is unsupported: all state changes must go through the `mark*()` transition methods, which throw `InvalidStateTransitionException` for anything the state machine forbids.

## Persistence

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Workflow\ExecutionRepository` | Public (contract) | The supported way to read executions, and the persistence boundary: `create` (with plan snapshot), `find`, `claim` (compare-and-set, retry-schedule-gated), `steps`, `attempts`, `attemptHistory` (whole execution, one query), `stepProgress` (step counts for many executions, one query), `list` (filtered, keyset-paginated, bounded by `MAX_PAGE_SIZE`), `count`, `saveStep` and `checkpoint` (both transactional, claim-verified, attempt-history-carrying), `release`, `requestCancellation`, plus the recovery scans `stale`, `pendingBefore`, and `dueForRetry`. Custom implementations must honour the guarantees in [docs/execution-lifecycle.md](execution-lifecycle.md) and [docs/retry-and-failure-policy.md](retry-and-failure-policy.md). **Breaking in Milestone 3:** `claim()` gained `$ignoreRetrySchedule` and may throw `RetryNotDueException`; `saveStep()`/`checkpoint()` gained an optional `StepAttempt`; `attempts()`, `dueForRetry()`, and `requestCancellation()` were added. **Breaking in Milestone 4:** `create()` gained a trailing optional `?ExecutionOptions $options`; `dueForRetry()` gained `?string $tenantId` (the listing methods scope tenants through `ExecutionFilter` instead), `retentionCandidates()`, `scrubPayloads()`, `pruneAttempts()`, and `deleteExecutions()` were added, together with the `MAX_PAGE_SIZE` constant. **Breaking in Milestone 7:** `saveStep()` gained a leading `WorkflowExecution $execution` and verifies the claim, throwing `ClaimLostException` like `checkpoint()` and `release()`; `recordSignal()` and the `waitingDue()` scan were added for wait states; `stepProgress()` gained a `skipped` count; `ExecutionFilter` gained `parentId` and `ExecutionOptions` the parent linkage ids. |
| `Laraflow\Workflow\ExecutionFilter` | Public | Criteria for `list()`/`count()`: workflow(s), status(es), `correlationId`, `tenantId`, half-open `created`/`finished` windows, `oldestFirst`, plus `withStatus()` and `withOldestFirst()` withers. Every criterion is served by an index; a filter Laraflow cannot bound is a filter it does not offer. Validated at construction. |
| `Laraflow\Workflow\ExecutionPage` | Public | One page from `list()`: `executions`, `nextCursor` (opaque; null on the last page), the `limit` actually applied, `hasMore()`, `isEmpty()`; countable and iterable. |
| `Laraflow\Workflow\Repositories\EloquentExecutionRepository` | Internal | Default binding. Swap via the container if needed. Organised with `Repositories\Concerns\{QueriesExecutions, ScansForRecovery, AppliesRetention}`; the contract itself stays a single interface. |
| `Laraflow\Workflow\Models\WorkflowExecutionModel` | Internal | Table layout will change. |
| `Laraflow\Workflow\Models\StepExecutionModel` | Internal | Table layout will change. |
| `Laraflow\Workflow\Models\StepAttemptModel` | Internal | Table layout will change. |
| Database tables `laraflow_workflow_executions`, `laraflow_step_executions`, `laraflow_step_attempts` | Internal | Do not write to them. Reading for debugging is fine. The schema is frozen, but it is frozen as an *implementation* — columns may still be added, and the `ExecutionRepository` contract is what carries the compatibility promise. |

## Integrations

Specified in [connectors.md](connectors.md).

| Class | Level | Notes |
|-------|-------|-------|
| `Laraflow\Core\LaraflowManager` | Public | `register(name, class-string|closure)`, `connector(name, ?connection)`, `has(name)`, `connectors()`, `connections(name)`, `connection(name, ?connection)`, `forget(?name, ?connection)`. A class-string is built through the container with the resolved `Connection` passed as `$connection`; a closure receives it. Instances are built on first use and cached per connection until `forget()`. **Breaking in Milestone 5:** `connector()` gained the optional connection name; closures receive a `Connection`. |
| `Laraflow\Integration\Connection` | Public | One named account on one connector: `connector`, `name`, `config`, `get()`, `has()`, `require(key, ?envHint)` (blank counts as missing; throws `ConnectorConfigurationException` naming the path), `path()`, `key()` (`github:default`). |
| `Laraflow\Integration\Connector` | Public (contract) | `name()` and `request(ConnectorRequest): ConnectorResponse`. |
| `Laraflow\Integration\HttpConnector` | Public | Abstract base for HTTP connectors. A subclass declares `baseUrl()` and optionally `headers()`, `authenticator()` (bearer `token` by default), `errorMapper()` (by status by default), `paginator()`, `apiVersion()`/`apiVersionHeader()` (the provider API version it was written against and the header it is sent in; a connection's `api_version` pins another), `throwsOnError()` (the connection's `throw_on_error`, false by default), and `transport()`; it inherits `request()` — resolve credentials, authenticate, send, map — plus `pages()`/`items()` (lazy, bounded pagination; see `paginator()`, with an optional per-call `Paginator` override for providers whose result shape differs per endpoint), `name()` (the registered name), `connection()`, `options()` (the resolved `HttpOptions`), and `providerApiVersion()`. An error response is **returned with its exception attached**, not thrown, unless `throwsOnError()`. Constructed by the container with `Connection`, `CredentialProvider`, `HttpTransport`, and the `HttpOptions` defaults. |
| `Laraflow\Integration\Http\HttpOptions` | Public | How one connection speaks HTTP: `baseUrl`, `timeoutSeconds`, `connectTimeoutSeconds`, `headers`; `forConnection()` applies a connection's `base_url`, `timeout`, `connect_timeout`, `headers` over the class's values over the `laraflow.http` defaults. Validated at construction. `host()` is the only part safe for an error message. |
| `Laraflow\Integration\Http\TransportPolicy` | Public | Whether and how quickly one wire request is repeated **inside a single step attempt**: `maxAttempts` (1 = off, the default), millisecond backoff with deterministic jitter, retried `statuses` (429, 500, 502, 503, 504), `retryTransportFailures`, and `maxRetryAfterSeconds` (5) — a longer `Retry-After` is returned to the step rather than waited for. A non-idempotent request is repeated only on 429 or when marked `asIdempotent()`. Configured as `laraflow.http.retries` and a connection's `retries`; `fromArray()` merges a partial array over a base. Specified in [retry-and-failure-policy.md](retry-and-failure-policy.md#transport-retries-are-the-inner-loop). |
| `Laraflow\Integration\Http\RateLimitPolicy` | Public | A ceiling Laraflow holds itself to per connection: `maxRequests` per `perSeconds`, counted in the application's cache so every worker shares it, with `maxWaitSeconds` (0) served in-process before a longer wait is refused with `ThrottledException`. None unless configured as `laraflow.http.rate_limit` or a connection's `rate_limit` (`max`, `per`, `max_wait`). |
| `Laraflow\Integration\Http\ObservingTransport` | Internal | Outermost pipeline layer: times the call, names the connector in the log context for its duration, and announces the outcome. |
| `Laraflow\Integration\Http\ConnectorObserver` | Internal | Builds the connector events, applying the same redact-then-truncate policy as the runner. |
| `Laraflow\Integration\Http\ThrottlingTransport` | Internal | Applies a `RateLimitPolicy` before each wire attempt through `Illuminate\Cache\RateLimiter`. |
| `Laraflow\Integration\Http\RetryingTransport` | Internal | The decorator that applies a `TransportPolicy`; the response it returns reports `attempts()`. |
| `Laraflow\Integration\Http\TransportPipeline` | Internal | Wraps a connector's base transport in whatever its options enable; with the shipped defaults, nothing. |
| `Laraflow\Support\Sleeper` | Internal | Pauses the process; bound by interface so tests record waits instead of serving them. Default `SystemSleeper`. |
| `Laraflow\Integration\Http\HttpTransport` | Public (contract) | `send(ConnectorRequest $authenticated, HttpOptions): ConnectorResponse`. Throws `TransportException` only when no HTTP response was obtained; an error status is a response. Bind your own to replace the wire for every connector. |
| `Laraflow\Integration\Http\LaravelHttpTransport` | Internal | Default binding on Laravel's HTTP client; `Http::fake()` applies. Merges connection headers under request headers itself so two values for one header never become a list. |
| `Laraflow\Integration\ConnectorRequest` | Public | Immutable (`readonly`) value object: method, uri, options, and an optional `idempotent` flag; `isIdempotent()` derives it from the method (GET, HEAD, OPTIONS, PUT, DELETE) unless `asIdempotent()` said otherwise; `withOptions()` and `withHeaders()` withers. |
| `Laraflow\Integration\ConnectorResponse` | Public | Immutable. `status()`, `headers()` (name → list), `header(name)` (case-insensitive, first value), `raw()` (the body as received), `isJson()`, `json(?dotKey, default)` (decodes regardless of content type; malformed JSON throws `ResponseDecodingException`), `body()` (decoded when the server declared JSON, the raw string otherwise, null when empty), `successful()` (2xx **and** no error attached), `failed()`, `error()` (the mapped `RequestFailedException`, if any), `withError()`, `throw()` (raises the attached error, or a generic one for an unmapped error status; returns the response otherwise), `toArray()` (a durable form for step output), `attempts()` (wire attempts a transport retry policy spent), `retryAfterSeconds(now)`. `ConnectorResponse::fake(status, json, headers)` builds one for tests and replacement transports. **Breaking in Milestone 5:** constructed from the raw body, not a decoded one; a non-JSON body is no longer `null`. |
| `Laraflow\Integration\Pagination\Paginator` | Public (contract) | `next(request, response): ?ConnectorRequest` and `items(response): array`. A connector supplies one from `paginator()`; `HttpConnector::pages()` and `::items()` drive it. |
| `Laraflow\Integration\Pagination\LinkHeaderPaginator` | Public | RFC 8288 `Link` header (GitHub's shape). Configurable relation and items path. The next URL is absolute, so `HttpConnector` checks its host before following it. |
| `Laraflow\Integration\Pagination\CursorPaginator` | Public | A cursor in the body sent back as a query parameter (Slack's shape). Configurable cursor path, parameter, and items path; a blank cursor ends it. |
| `Laraflow\Integration\Pagination\PagePaginator` | Public | A page number incremented until a page comes back empty. Configurable parameter, first page, and items path. |
| `Laraflow\Integration\Http\ErrorMapper` | Public (contract) | `map(ConnectorRequest $asMade, ConnectorResponse, Connection): ?RequestFailedException`. Receives the request *before* authentication, so nothing an authenticator added can reach a message. |
| `Laraflow\Integration\Http\StatusErrorMapper` | Public | The default mapper, by status: 401/403 → `AuthenticationFailedException`, 404 → `NotFoundException`, 429 → `RateLimitedException`, other 4xx → `ClientErrorException`, 5xx → `ServerErrorException`, below 400 → none. Takes the provider's detail from the usual JSON fields. Extend it and fall back to `parent::map()`. |
| `Laraflow\Testing\ConnectorContract` | Public | The assertions a connector should satisfy, published for connectors Laraflow does not ship: `assertHeldBy(connector, credential, probe)` runs all of them, or call `assertIsNamed`, `assertIsInterceptable`, `assertHoldsNoCredentials`, `assertClassifiesErrors`, `assertReturnsErrorsRatherThanThrowing`, `assertHidesCredentialsInErrors` individually. A plain object, not a base class or trait, so it collides with nothing and works from PHPUnit, Pest, or Testbench. Installs one HTTP fake with stray requests prevented — use it in a test that does not install its own. The `STATUSES` map may be overridden at construction. |
| `Laraflow\Integration\Slack\SlackConnector` | Experimental | Slack's Web API. `read(method, args)` builds a `GET` with a query; `write(method, args)` a `POST` with a JSON body (not idempotent); `listing(itemsKey)` a cursor paginator for one listing method, since Slack's results live under a different key per endpoint. Built on the public contracts alone, enforced by `ProjectContractsTest`. |
| `Laraflow\Integration\Slack\SlackErrorMapper` | Experimental | Maps Slack's `200 {"ok": false, "error": ...}` envelope onto the shared exception types, so an application catches `AuthenticationFailedException` without knowing which provider raised it. Non-2xx statuses fall through to `StatusErrorMapper`. |
| `Laraflow\Integration\Github\GithubErrorMapper` | Experimental | `403` with `X-RateLimit-Remaining: 0` is rate limiting, not an authentication failure. |
| `Laraflow\Integration\CredentialProvider` | Public (contract) | `credentials(Connection): Credentials`. Called when a request is built, every time, so rotation needs no rebuild. Bind your own to read a vault or a per-tenant store. |
| `Laraflow\Integration\Credentials` | Public | The secrets of one connection, each a `Secret`: `get()`, `has()`, `require(key, ?envHint)` (throws `ConnectorConfigurationException` naming `…connections.{name}.credentials.{key}`), `keys()`. Blank values are dropped at construction. |
| `Laraflow\Support\Secret` | Public | A credential that renders as `[redacted]` in string context, `json_encode`, `print_r`, `var_dump`, `var_export`, the array cast, and `serialize`; the value is reached only by `reveal()`. Not cloneable. An unserialized `Secret` has no value and `reveal()` throws `SecretRedactedException`. |
| `Laraflow\Integration\ConfigCredentialProvider` | Internal | Default binding: reads `laraflow.integrations.{connector}.connections.{name}.credentials` from the config repository on every call. |
| `Laraflow\Auth\Authenticator` | Public (contract) | `authenticate(array $options, Credentials $credentials): array`. Receives the credentials rather than holding them. **Breaking in Milestone 5:** gained the `Credentials` parameter. |
| `Laraflow\Auth\BearerTokenAuthenticator` | Public | `Authorization: Bearer` from the named credential (default `token`), optionally naming the environment variable for the missing-credential error. Replaces `GithubTokenAuthenticator`, removed in Milestone 5. |
| `Laraflow\Integration\Github\GithubConnector` | Experimental | On `HttpConnector`: `api.github.com`, the `application/vnd.github+json` accept header, bearer `token` credential, `Link` header pagination, and REST API version `2022-11-28` sent as `X-GitHub-Api-Version`. Kept experimental until the first release. |
| `Laraflow\Integration\ConnectorRegistry` | Internal | Definitions, configured connections, and the instance cache behind `LaraflowManager`. Replaced `ConnectorFactory` and `ConnectorManager` in Milestone 5. |

## Exceptions

| Class | Level |
|-------|-------|
| `Laraflow\Exceptions\LaraflowException` | Public. Base class for all package exceptions. |
| `Laraflow\Exceptions\ConnectorException` | Public. Base class for connector exceptions; also thrown directly for configuration problems such as a missing token. |
| `Laraflow\Exceptions\ConnectorNotRegisteredException` | Public. Unknown connector name. |
| `Laraflow\Exceptions\InvalidConnectorException` | Public. A registered class is not a `Connector`, or a factory returned something that is not one. |
| `Laraflow\Exceptions\ConnectorConfigurationException` | Public. The `laraflow.integrations` section cannot be used as written: a required setting is missing or blank, a key sits at the wrong level, or a name is invalid. Always names the configuration path. |
| `Laraflow\Exceptions\ConnectionNotConfiguredException` | Public. A connector was asked for a connection its configuration does not declare; names the configured ones. |
| `Laraflow\Exceptions\SecretRedactedException` | Public. `reveal()` on a `Secret` that was unserialized and so carries no value. |
| `Laraflow\Exceptions\TransportException` | Public. No HTTP response was obtained: connection failed, refused, timed out, or the client gave up (too many redirects). The message names the host only — never the request options (they carry credentials) nor the URL (its query may). `attempts` is the wire attempts a transport retry policy spent before giving up. |
| `Laraflow\Exceptions\Connector\RequestFailedException` | Public. The provider answered with an error. Carries the `response`; `status()`. The message names connection, method, **path only** (a query string may carry a token), status, and the provider's detail, truncated. Base of the five below; `fromResponse()` builds the right subclass' message, `forStatus()` the generic one `throw()` uses for an unmapped error. |
| `Laraflow\Exceptions\Connector\AuthenticationFailedException` | Public. 401 or 403. |
| `Laraflow\Exceptions\Connector\NotFoundException` | Public. 404. |
| `Laraflow\Exceptions\Connector\RateLimitedException` | Public. 429, or a provider-specific signal. `retryAfter()` is the header as sent; `retryAfterSeconds(DateTimeInterface $now)` reads delay-seconds or an HTTP-date against the caller's clock, null when unusable. |
| `Laraflow\Exceptions\Connector\ClientErrorException` | Public. Any other 4xx. |
| `Laraflow\Exceptions\Connector\ServerErrorException` | Public. 5xx. |
| `Laraflow\Exceptions\Connector\ResponseDecodingException` | Public. A body could not be decoded as asked. Names status and declared content type, never the body. |
| `Laraflow\Exceptions\Connector\PaginationException` | Public. Paging could not continue safely: the page budget was reached (**an error, not a quiet stop** — a short list that looks complete is the worst outcome), the connector declares no paginator, the provider repeated a page, or a next-page link pointed at a host the connection never named. |
| `Laraflow\Exceptions\Connector\ThrottledException` | Public. Laraflow's own rate limit refused the request before it was sent and the wait exceeded the connection's `max_wait`; `availableInSeconds`. Suggests that wait to the runner. |
| `Laraflow\Exceptions\SuggestsRetryDelay` | Public (contract). `suggestedRetryDelaySeconds(DateTimeInterface $now): ?int`. When a step fails with an exception implementing it and the policy allows a retry, the runner schedules the retry no sooner than the suggestion, up to `laraflow.retry.max_suggested_delay`, and never sooner than the policy's own backoff. Implemented by `RateLimitedException` (the provider's `Retry-After`) and `ThrottledException`. Any application exception may implement it. |
| `Laraflow\Exceptions\Workflow\WorkflowException` | Public. Base class for workflow/lifecycle exceptions. |
| `Laraflow\Exceptions\Workflow\ExecutionNotFoundException` | Public. Unknown execution id. |
| `Laraflow\Exceptions\Workflow\ExecutionAlreadyClaimedException` | Public. Another worker holds a fresh claim. |
| `Laraflow\Exceptions\Workflow\ExecutionNotRunnableException` | Public. Execution is in a terminal status. |
| `Laraflow\Exceptions\Workflow\ClaimLostException` | Public. This worker's claim was taken over; nothing was persisted. |
| `Laraflow\Exceptions\Workflow\WaitTimedOutException` | Public. A signal wait passed its deadline; an ordinary step failure under the step's retry policy. |
| `Laraflow\Exceptions\Workflow\SignalRefusedException` | Public. The execution is not waiting on that signal — resumed, timed out, finished, or a different name. |
| `Laraflow\Exceptions\Workflow\ExecutionStillWaitingException` | Public. A claim was refused because the execution's wait is not due; indefinite signal waits say so. |
| `Laraflow\Exceptions\Workflow\ChildWorkflowFailedException` | Public. A sub-workflow ended failed, cancelled, or missing; fails the parent step under its retry policy. |
| `Laraflow\Exceptions\Workflow\WorkflowDepthExceededException` | Public. Creating a child would nest past `laraflow.workflows.max_depth`; refused at creation. |
| `Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException` | Public. `start()` rejected the definition: no steps, or a step that is not a class implementing `Step`. Nothing is persisted. |
| `Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException` | Public. Supplied workflow does not match the persisted plan. |
| `Laraflow\Exceptions\Workflow\InvalidStateTransitionException` | Public. Forbidden execution/step state transition. |
| `Laraflow\Exceptions\Workflow\UnserializableValueException` | Public. Input/output/context value violates the serialization contract. |
| `Laraflow\Exceptions\Workflow\PayloadTooLargeException` | Public. A durable payload exceeded `laraflow.execution.max_payload_bytes`. Never retried. |
| `Laraflow\Exceptions\Workflow\RetryNotDueException` | Public. A `retrying` execution was claimed before its `next_retry_at`; override deliberately with `--now`. |
| `Laraflow\Exceptions\Workflow\StepTimeoutException` | Public. Recorded failure of an attempt that passed its deadline and was never checkpointed. |
| `Laraflow\Exceptions\Workflow\ExecutionNotCancellableException` | Public. Cancellation of an execution that has already completed. |
| `Laraflow\Exceptions\Workflow\InvalidExecutionOptionsException` | Public. An `ExecutionOptions` identifier was too long or contained control characters. Thrown at construction, before anything is persisted. |

Step exceptions are *not* wrapped: `run()` rethrows whatever the step threw after persisting the failure.

## Configuration and service provider

| Item | Level | Notes |
|------|-------|-------|
| `Laraflow\Core\LaraflowServiceProvider` | Public | Auto-discovered. |
| `config/laraflow.php` keys `migrations.load`, `execution.stale_after`, `execution.max_error_length`, `execution.max_payload_bytes` (default `null` — no limit) | Public | |
| `config/laraflow.php` keys `http.retries.*` (`max_attempts` default 1 — off, `base_delay_ms`, `multiplier`, `max_delay_ms`, `jitter`, `statuses`, `transport_failures`, `max_retry_after`) | Public | The in-attempt transport retry defaults; a connection's `retries` array overrides any subset. |
| `config/laraflow.php` key `http.pagination.max_pages` (default 100) | Public | Pages one pagination may fetch before refusing to continue. A connection's `max_pages` overrides it, as does a `maxPages` argument. |
| `config/laraflow.php` key `http.rate_limit` (default `null`; `max`, `per`, `max_wait`) | Public | A per-connection ceiling for every connection; a connection's `rate_limit` overrides any subset. Requires a cache store. |
| `config/laraflow.php` keys `http.timeout` (default 30) and `http.connect_timeout` (default 10), seconds | Public | Fleet-wide defaults every connection starts from. A connection's `base_url`, `timeout`, `connect_timeout`, and `headers` override the connector class, which overrides these. A connection's `throw_on_error` (default false) makes `request()` throw the mapped error instead of returning the failed response. |
| Connection keys `base_url`, `timeout`, `connect_timeout`, `headers`, `retries`, `rate_limit`, `max_pages`, `throw_on_error`, `api_version`, `credentials` | Public | What a connection may set. Anything else is the connector's own to read via `Connection::get()`. |
| `config/laraflow.php` key `integrations.slack.*` | Public | The bundled Slack connector, configured like any other; `LARAFLOW_SLACK_TOKEN` seeds its default connection's `token` credential. |
| `config/laraflow.php` key `integrations.{connector}` holding `connector`, `default`, and `connections.{name}` — and nothing else | Public | Declaring `connector` registers the class at boot. Within a connection, `credentials` is reserved: read by the `CredentialProvider` per request and never placed on the `Connection`. A setting at the connector level (the Milestone 0 `integrations.github.token`) is refused with the path it belongs at. **Breaking in Milestone 5:** `integrations.github.token` moved to `integrations.github.connections.default.credentials.token`. |
| `config/laraflow.php` keys `queue.*` (`connection`, `queue`, `tries`, `backoff`, `timeout`, `after_commit`), `recovery.*` (`limit`, `abandon_after_attempts`, `pending_after`), `events.include_error_messages` | Public | Defaults and their rationale in [docs/queue-and-recovery.md](queue-and-recovery.md). `queue.tries` defaults to 1 and `recovery.pending_after` to null (pending scan disabled), deliberately. **Breaking in Milestone 3:** `recovery.max_attempts` was renamed `recovery.abandon_after_attempts`. |
| `config/laraflow.php` keys `logging.context` (default `true`) and `logging.lifecycle` (default `false`) | Public | `context` publishes the running execution's identity into Laravel's log context under `laraflow.*` keys; `lifecycle` writes one log record per lifecycle event. |
| `config/laraflow.php` keys `retry.*` (`max_attempts`, `base_delay`, `multiplier`, `max_delay`, `jitter`, `dispatch_delayed`, `max_suggested_delay` — default 3600, the cap on a `SuggestsRetryDelay`), `timeout.step`, `errors.redactor` | Public | Defaults and rationale in [docs/retry-and-failure-policy.md](retry-and-failure-policy.md). `retry.max_attempts` defaults to 1 — retries off — deliberately. |
| `config/laraflow.php` key `workflows.max_depth` (default 8) | Public | How deep sub-workflows may nest, counted from the root; exceeded nesting is refused at child creation with `WorkflowDepthExceededException`. |
| Publish tags `laraflow-config`, `laraflow-migrations` | Public | |

## Console commands

All experimental: names, signatures, and output format may change while the operational tooling (roadmap Milestones 2 and 4) takes shape.

| Command | Level | Notes |
|---------|-------|-------|
| `laraflow:demo {--fail} {--queue}` | Experimental | Runs the bundled `Laraflow\Demo\DemoWorkflow`. `--fail` makes the payment step fail on attempt 1 so recovery can be demonstrated; `--queue` dispatches it instead of running it inline. |
| `laraflow:list {--status=*} {--workflow=*} {--correlation=} {--tenant=} {--since=} {--until=} {--limit=20} {--cursor=} {--oldest}` | Experimental | Lists executions, newest first, through the public `ExecutionRepository` query API. Reports how many of the matching set the page showed, and prints the command that continues it. `--status` and `--workflow` are repeatable; times accept absolute (`2026-10-24 09:00:00`) or relative (`-2 hours`) values. |
| `laraflow:summary {--workflow=*} {--correlation=} {--tenant=} {--since=} {--until=}` | Experimental | Counts executions by status for a scope, then surfaces retries that are due, running steps past their deadline, and the oldest unfinished execution. **Exits non-zero when anything needs attention**, so it can be used as a scheduled health check. Deliberately not a per-workflow breakdown: that needs a `GROUP BY` the repository contract does not expose; `--workflow=` scopes the same numbers instead. |
| `laraflow:show {execution} {--attempts}` | Experimental | One execution in detail: steps, attempts, outputs, errors, context, claim, correlation and tenant identifiers, finish time, retry schedule, cancellation, and an `(overdue)` marker on a running step past its deadline. `--attempts` adds the per-attempt history, read in one query via `attemptHistory()`. |
| `laraflow:execute {execution} {--queue} {--now}` | Experimental | Executes or recovers an execution using the workflow class stored on it; refuses if that class no longer exists or is not a `Workflow`. `--queue` hands it to a worker instead; `--now` runs a `retrying` execution ahead of its schedule. No attempt bound — this is the deliberate operator override. |
| `laraflow:cancel {execution} {--reason=}` | Experimental | Cancels an execution, or requests cooperative cancellation from the worker running it. |
| `laraflow:connectors {connector?} {--check}` | Experimental | Lists registered connectors, their connections, base URL, timeout, retry and rate-limit settings, and the **names** of the credentials each connection resolves — never their values. `--check` resolves every connector, reports what will not build, and **exits non-zero**, so it can gate a deploy. Sends no requests. |
| `laraflow:prune {--scrub-after=} {--prune-attempts-after=} {--delete-after=} {--delete-failed-after=} {--limit=} {--chunk=} {--workflow=} {--tenant=} {--dry-run} {--force}` | Experimental | Applies the retention policy to finished executions. Each option overrides the matching `laraflow.retention.*` value for one run. **Exits non-zero when no bound is configured**, because a scheduled prune that silently does nothing looks exactly like one that is working. Deleting is irreversible, so it uses Laravel's standard production confirmation — schedule it as `laraflow:prune --force`. A dry run changes nothing and never asks. |
| `laraflow:recover {--stale-after=} {--pending-after=} {--limit=} {--abandon-after=} {--skip-retries} {--workflow=} {--dry-run} {--sync}` | Experimental | Finds interrupted executions and due retries and runs them within a bounded policy. Exits non-zero when work was abandoned, unrecoverable (workflow class missing), or an action failed. Intended for the scheduler; see [docs/queue-and-recovery.md](queue-and-recovery.md#recovering-interrupted-work). |

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
