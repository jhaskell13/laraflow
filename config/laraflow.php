<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | By default Laraflow loads its migrations directly from the package so
    | that `php artisan migrate` creates the execution tables without any
    | extra steps. Set `load` to false if you prefer to publish and manage
    | the migrations yourself:
    |
    |   php artisan vendor:publish --tag=laraflow-migrations
    |
    */

    'migrations' => [
        'load' => env('LARAFLOW_LOAD_MIGRATIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    |
    | stale_after: seconds after which a running execution's claim is
    | considered abandoned (worker death) and may be reclaimed by another
    | worker via execute(). Set it comfortably above your longest step.
    |
    | max_error_length: step/execution error messages are truncated to this
    | many characters before persisting.
    |
    | max_payload_bytes: the largest a single durable payload may be —
    | the workflow input, one step's output, or the context snapshot —
    | measured as the JSON that reaches the column. Null (the default)
    | means the only limit is your database's.
    |
    | Setting it turns a growth problem into an immediate, named error:
    | without a limit an oversized payload still fails, but as a driver
    | error about a column or a max_allowed_packet, raised from inside
    | the checkpoint transaction and much harder to attribute. With one,
    | the step fails with PayloadTooLargeException naming the boundary.
    | The check is never retried — the same step will produce the same
    | size — and is a sensible companion to retention: payloads you
    | never meant to store are the ones that make a table unprunable.
    |
    */

    'execution' => [
        'stale_after' => env('LARAFLOW_STALE_AFTER', 300),
        'max_error_length' => env('LARAFLOW_MAX_ERROR_LENGTH', 65535),
        'max_payload_bytes' => env('LARAFLOW_MAX_PAYLOAD_BYTES'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | The default retry policy for steps that declare none of their own
    | (via Policy\HasStepPolicy) and whose workflow declares no default.
    |
    | max_attempts counts the first attempt, so the shipped value of 1
    | means NO step is ever retried automatically. Retries are opt-in,
    | like every other retry authority in Laraflow: nothing re-runs your
    | side effects unless you asked for it.
    |
    | Delays grow as base_delay * multiplier^(attempt-1), capped at
    | max_delay, then spread by a ± jitter fraction. The jitter is
    | derived from the execution id rather than randomness, so a
    | schedule is reproducible while retries of different executions
    | still avoid landing together.
    |
    | dispatch_delayed: when an execution running on a queue worker
    | schedules a retry, push a delayed job for the due time — the
    | low-latency delivery path. The laraflow:recover scan remains the
    | contractual pickup either way: it needs no queue, and it catches
    | anything the fast path loses. The fast path is always skipped on
    | the sync driver, which ignores delays and would turn backoff into
    | immediate recursion.
    |
    */

    'retry' => [
        'max_attempts' => env('LARAFLOW_RETRY_MAX_ATTEMPTS', 1),
        'base_delay' => env('LARAFLOW_RETRY_BASE_DELAY', 5),
        'multiplier' => env('LARAFLOW_RETRY_MULTIPLIER', 2.0),
        'max_delay' => env('LARAFLOW_RETRY_MAX_DELAY', 300),
        'jitter' => env('LARAFLOW_RETRY_JITTER', 0.2),
        'dispatch_delayed' => env('LARAFLOW_RETRY_DISPATCH_DELAYED', true),

        // The longest retry delay an exception may ask for by implementing
        // SuggestsRetryDelay — a provider's Retry-After on a 429, or
        // Laraflow's own throttle. Honoured up to this many seconds, and
        // never shorter than the policy's own backoff.
        'max_suggested_delay' => env('LARAFLOW_RETRY_MAX_SUGGESTED_DELAY', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | step: the default per-attempt deadline in seconds for steps that
    | declare no policy of their own; null means no deadline.
    |
    | Laraflow records the deadline and exposes it to the step, but does
    | not interrupt running PHP: a step is expected to bound its own
    | work (pass the deadline to your HTTP client), and an attempt that
    | overran is classified at the recovery boundary. Keep any step
    | deadline below queue.timeout, which must stay below
    | execution.stale_after.
    |
    */

    'timeout' => [
        'step' => env('LARAFLOW_STEP_TIMEOUT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Settings for executions dispatched onto the queue via the
    | WorkflowDispatcher. `connection` and `queue` default to null, meaning
    | your application's default queue connection and queue are used.
    |
    | tries/backoff/timeout apply to the queued job. `tries` defaults to 1:
    | the queue does not retry a failed execution, because queue retries and
    | Laraflow's own attempt tracking would otherwise multiply. Re-running a
    | failed execution is an explicit operation (laraflow:execute or
    | laraflow:recover). If you raise `tries`, every queue retry re-executes
    | from the last checkpoint and step attempts accumulate.
    |
    | `timeout` must stay below `execution.stale_after`, otherwise a job
    | that is still legitimately running can have its claim reclaimed as
    | stale by another worker.
    |
    | `after_commit` delays the queue push until the application's database
    | transactions have committed, so a worker never receives an execution
    | id that is not yet visible. Disable only if you do not dispatch inside
    | transactions and want lower latency.
    |
    */

    'queue' => [
        'connection' => env('LARAFLOW_QUEUE_CONNECTION'),
        'queue' => env('LARAFLOW_QUEUE'),
        'tries' => env('LARAFLOW_QUEUE_TRIES', 1),
        'backoff' => env('LARAFLOW_QUEUE_BACKOFF', 0),
        'timeout' => env('LARAFLOW_QUEUE_TIMEOUT', 60),
        'after_commit' => env('LARAFLOW_QUEUE_AFTER_COMMIT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    |
    | Defaults for the laraflow:recover command, which finds interrupted
    | work (running executions with a stale claim, and pending executions
    | whose dispatch was lost), re-dispatches it, and delivers scheduled
    | work that has come due — retries, waits, recorded signals, and
    | sub-workflow heartbeats — the contractual pickup behind
    | retry.dispatch_delayed, and the only delivery path for in-process
    | and sync-driver runs.
    |
    | limit: maximum executions handled per run, most urgent first.
    |
    | abandon_after_attempts: an *interrupted* execution where any step
    | has already been attempted this many times — or which has been
    | claimed this many times without reaching a step or an orderly
    | release — is reported as
    | abandoned instead of re-dispatched, so a crash-looping workflow
    | cannot be retried forever by the scheduler. This bound is crash-loop
    | protection and deliberately independent of step retry policies,
    | which govern *failures* through the retrying state and carry their
    | own budget; due retries are exempt from it. If your retry budgets
    | exceed this value and your steps also risk crashing workers, raise
    | it to match. Abandoned executions can still be re-run manually via
    | laraflow:execute.
    |
    | pending_after: seconds after which a still-pending execution is
    | treated as having lost its queue dispatch and is re-dispatched.
    | Disabled (null) by default, deliberately: `pending` is also the state
    | of executions created with start() and intentionally not yet executed,
    | and recovery must not start that work behind the application's back.
    | Enable it (e.g. 300) only if every pending execution in your
    | application is meant to run immediately.
    |
    | redispatch_after: seconds a queue push is treated as in flight. A
    | due retry, due wait, or lost pending whose last push became
    | deliverable (available_at) more recently than this is left to the
    | worker that will take it, instead of being pushed again on every
    | recover run. Keep it above your normal queue latency; 0 re-pushes
    | everything due, as before 0.6.0. Stale claims are unaffected.
    |
    */

    'recovery' => [
        'limit' => env('LARAFLOW_RECOVERY_LIMIT', 100),
        'abandon_after_attempts' => env('LARAFLOW_RECOVERY_ABANDON_AFTER_ATTEMPTS', 3),
        'pending_after' => env('LARAFLOW_RECOVERY_PENDING_AFTER'),
        'redispatch_after' => env('LARAFLOW_RECOVERY_REDISPATCH_AFTER', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long finished executions are kept, and in what form. Applied
    | by the laraflow:prune command, which you schedule; Laraflow never
    | prunes on its own.
    |
    | Every bound is null by default. Retention is irreversible, and a
    | package that quietly began deleting your execution history on
    | upgrade would be indefensible. Nothing is removed until you say
    | what you want kept.
    |
    | All bounds count from finished_at — when the execution stopped —
    | so a long-running execution is never pruned for having started a
    | long time ago. Executions that are pending, running, retrying, or waiting
    | are invisible to retention: a retry waiting out its backoff is
    | future work, not old work.
    |
    | scrub_after_days: clear the input, context, and step outputs of
    | completed and cancelled executions, keeping the record itself as
    | an audit trail of what ran and when. Use this when payloads carry
    | personal data that should outlive neither its purpose nor your
    | retention policy. Never applied to failed executions: they need
    | their input and context to be re-executable.
    |
    | prune_attempts_after_days: delete the append-only attempt history
    | (laraflow_step_attempts), which is the table that grows without
    | bound under retry policies. Applies to every finished execution,
    | failed included: the step row keeps the latest attempt's status
    | and error, and the retry budget reads the step's counter rather
    | than this table, so nothing becomes unrecoverable.
    |
    | delete_after_days: delete completed and cancelled executions
    | outright, with their steps and attempts.
    |
    | delete_failed_after_days: the same for failed executions, kept
    | separate on purpose. A failed execution can still be recovered,
    | so discarding one should be a decision you took, not a side
    | effect of configuring general retention.
    |
    | prune_ticks_after_days: delete schedule tick history
    | (laraflow_schedule_ticks) older than this many days, counted from
    | when each tick was claimed. The table grows by one row per tick
    | that fires — about half a million a year for a per-minute
    | schedule. Pruning a tick never touches the execution it started,
    | and executions are pruned on their own bounds; the two never
    | reference each other by foreign key for exactly this reason.
    |
    | limit: most executions handled per tier per run. chunk: how many
    | are written at once.
    |
    */

    'retention' => [
        'scrub_after_days' => env('LARAFLOW_RETENTION_SCRUB_AFTER_DAYS'),
        'prune_attempts_after_days' => env('LARAFLOW_RETENTION_PRUNE_ATTEMPTS_AFTER_DAYS'),
        'delete_after_days' => env('LARAFLOW_RETENTION_DELETE_AFTER_DAYS'),
        'delete_failed_after_days' => env('LARAFLOW_RETENTION_DELETE_FAILED_AFTER_DAYS'),
        'prune_ticks_after_days' => env('LARAFLOW_RETENTION_PRUNE_TICKS_AFTER_DAYS'),
        'limit' => env('LARAFLOW_RETENTION_LIMIT', 1000),
        'chunk' => env('LARAFLOW_RETENTION_CHUNK', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflows
    |--------------------------------------------------------------------------
    |
    | max_depth: how deep sub-workflows may nest, counted from the root
    | execution. A child that would nest past this is refused at creation
    | with WorkflowDepthExceededException, so mutual recursion between
    | workflow classes is one exception rather than an unbounded tree.
    |
    | max_members: how many member executions one fan-out step may
    | declare. A wider group is refused with FanOutTooWideException
    | before any member is created — a group too wide to cancel is a
    | group too wide to run.
    |
    */

    'workflows' => [
        'max_depth' => env('LARAFLOW_WORKFLOWS_MAX_DEPTH', 8),
        'max_members' => env('LARAFLOW_WORKFLOWS_MAX_MEMBERS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | max_keys_per_execution: the most distinct keys one execution may
    | record through $context->remember(). A step remembering under a
    | generated key is usually a loop, and a table that grows with it
    | is the failure this bound exists to catch; past it the step fails
    | with TooManyIdempotencyKeysException. Raise it deliberately for a
    | workflow that genuinely needs more. See docs/idempotency.md.
    |
    */

    'idempotency' => [
        'max_keys_per_execution' => env('LARAFLOW_IDEMPOTENCY_MAX_KEYS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedules
    |--------------------------------------------------------------------------
    |
    | Scheduled workflows: a workflow declares its cadence in schedule(),
    | and listing the class here activates it. The tick run — which you
    | schedule, every minute, like laraflow:recover — synchronises the
    | declarations, claims each due tick exactly once across any number
    | of schedulers, and starts one execution per tick. Nothing below
    | holds a cron expression: the cadence lives on the class, where it
    | is reviewed and versioned with the steps it runs.
    |
    | enabled: the kill switch. Off, no schedule fires, without any
    | being unlisted — for the incident where nothing should start.
    |
    | workflows: the Workflow classes whose schedules are live in this
    | environment. A listed class with no schedule() is refused by name
    | rather than silently never firing.
    |
    | max_catch_up: under MissedTickPolicy::CatchUp, the most missed
    | ticks run per schedule per tick run; the remainder waits for the
    | next run. catch_up_window: how far back, in seconds, catch-up will
    | reach at all — missed ticks older than this are counted and
    | skipped, because a week-long outage should not replay a week of
    | hourly jobs without a person deciding it should.
    |
    | redispatch_after: seconds after which an execution a tick started
    | that is still pending has its (lost) queue push repeated by the
    | tick run. Safe to apply where the general pending scan must stay
    | opt-in: a scheduled execution is never work the application was
    | deliberately holding. See docs/schedules.md.
    |
    | limit: the most actions — ticks claimed plus lost dispatches
    | re-pushed — one tick run performs.
    |
    */

    'schedules' => [
        'enabled' => env('LARAFLOW_SCHEDULES_ENABLED', true),
        'workflows' => [],
        'max_catch_up' => env('LARAFLOW_SCHEDULES_MAX_CATCH_UP', 10),
        'catch_up_window' => env('LARAFLOW_SCHEDULES_CATCH_UP_WINDOW', 86400),
        'redispatch_after' => env('LARAFLOW_SCHEDULES_REDISPATCH_AFTER', 300),
        'limit' => env('LARAFLOW_SCHEDULES_LIMIT', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenants
    |--------------------------------------------------------------------------
    |
    | Per-tenant ceilings, enforced where every run begins: at the claim.
    | An execution created with a tenant id is held to its tenant's
    | ceilings on every claim — a fresh dispatch, a due retry, a resumed
    | wait, a heartbeat, recovery — and a claim a ceiling turns away
    | changes nothing about the execution: it stays pending, retrying,
    | or waiting as it was, with deferred_until and a deferral count on
    | the row, and the queue job pushes it again for that instant. An
    | execution with no tenant is never bounded. See docs/tenancy.md.
    |
    | max_concurrent: the most of a tenant's executions that may hold a
    | worker at once — running or compensating with a fresh claim.
    | Parked, pending, and stale executions hold nothing. Null: no
    | ceiling. Honest about the instant: two claimants in the same
    | instant can both count below it, so it is exact in the steady
    | state and exceeded briefly by at most the workers that raced.
    |
    | max_claims_per_minute: the most claims a tenant may make in a
    | sliding minute, counted in the cache like the connector rate
    | limit. Bounds churn where max_concurrent bounds occupancy.
    |
    | deferral: seconds a turned-away execution waits before its
    | delivery is tried again, plus up to a fifth of that in jitter
    | derived from the execution id so a tenant's deferred work does
    | not all wake at once.
    |
    | overrides: per-tenant exceptions, replacing only the keys named:
    |   'acme' => ['max_concurrent' => 20],
    |   'trial' => ['max_concurrent' => 1, 'max_claims_per_minute' => 10],
    |
    | The fairness rule: tenant B cannot be starved by tenant A if A's
    | ceiling is below the number of workers, because A can never hold
    | every worker. A tenant with no ceiling can still starve the rest;
    | max_concurrent here bounds every tenant without an override.
    |
    */

    'tenants' => [
        'max_concurrent' => env('LARAFLOW_TENANT_MAX_CONCURRENT'),
        'max_claims_per_minute' => env('LARAFLOW_TENANT_MAX_CLAIMS_PER_MINUTE'),
        'deferral' => env('LARAFLOW_TENANT_DEFERRAL', 15),
        'overrides' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Errors
    |--------------------------------------------------------------------------
    |
    | redactor: class name of a Laraflow\Support\ErrorRedactor
    | implementation applied to every exception message before Laraflow
    | retains it — persisted error columns, attempt history, log records,
    | and failure events all receive the redacted form. Null disables
    | redaction. The exception your application catches is untouched, and
    | console command output (operator-facing) is not redacted.
    |
    */

    'errors' => [
        'redactor' => env('LARAFLOW_ERROR_REDACTOR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | context: publish the running execution's identity into Laravel's
    | log context (laraflow.execution_id, workflow, step, position,
    | attempt, correlation_id, tenant_id) for as long as a step is
    | running. Every log record your application writes from inside a
    | step then carries them without you passing anything, and Laravel
    | propagates the context into any job the step dispatches. Keys are
    | prefixed and restored on the way out, so nothing of yours is
    | overwritten and nothing of ours lingers after the run.
    |
    | lifecycle: write one log record per lifecycle event — a ready-made
    | execution trail for applications that do not want to register
    | listeners. Off by default: an application already listening to
    | these events, or shipping them to a metrics backend, should not
    | also pay for a log line per step. Failures log at error, retries
    | and failed attempts at warning, everything else at info.
    |
    */

    'logging' => [
        'context' => env('LARAFLOW_LOG_CONTEXT', true),
        'lifecycle' => env('LARAFLOW_LOG_LIFECYCLE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Lifecycle events never contain workflow input, step output, or context
    | — only identifiers and metadata. Failure events include the exception
    | class, and (when enabled here) the exception message, truncated to
    | execution.max_error_length. Disable this if exception messages in your
    | application may carry sensitive data.
    |
    */

    'events' => [
        'include_error_messages' => env('LARAFLOW_EVENT_ERROR_MESSAGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Defaults every connector connection starts from. A connector class
    | overrides them for its provider, and a connection overrides both
    | with its own base_url, timeout, connect_timeout, and headers.
    |
    | timeout: the whole request, in seconds, connection included. Keep it
    | below any step deadline the request runs under, or the step will be
    | classified as timed out while the request is still waiting.
    |
    | connect_timeout: establishing the connection alone, in seconds.
    |
    | retries: repeating one wire request inside a single step attempt,
    | for a dropped connection or a momentary 503. Off by default
    | (max_attempts = 1). This is the inner loop and it holds a worker
    | while it waits, so it is measured in milliseconds; a step's own
    | retry policy is the durable outer loop, and the two are kept from
    | multiplying: a Retry-After longer than max_retry_after is not
    | waited for here but returned, so the step can fail and retry
    | durably with the provider's own schedule. A request that is not
    | idempotent is repeated only on 429 (never processed) or when the
    | caller marked it safe with asIdempotent().
    |
    */

    'http' => [
        'timeout' => env('LARAFLOW_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('LARAFLOW_HTTP_CONNECT_TIMEOUT', 10),

        'retries' => [
            'max_attempts' => env('LARAFLOW_HTTP_RETRY_ATTEMPTS', 1),
            'base_delay_ms' => 200,
            'multiplier' => 2.0,
            'max_delay_ms' => 2000,
            'jitter' => 0.2,
            'statuses' => [429, 500, 502, 503, 504],
            'transport_failures' => true,
            'max_retry_after' => 5,
        ],

        // A ceiling Laraflow holds itself to, per connection, counted in
        // the cache so every worker shares it: ['max' => 60, 'per' => 60,
        // 'max_wait' => 0]. Null: no ceiling. When the budget is spent, a
        // wait up to max_wait seconds is served in-process; a longer one
        // fails the request with ThrottledException, which suggests the
        // wait so a step with a retry policy comes back when a slot is
        // due, without holding a worker. Set it on a connection for one
        // account, or here for every connection; a connection sets
        // rate_limit => false to lift a ceiling set here.
        //
        // 'scope' => 'tenant' counts one budget per connection *per
        // tenant* of the running execution (its durable tenant_id), for
        // an account many tenants share, so one tenant's burst cannot
        // spend the ceiling the others are held to. The default,
        // 'connection', is one budget for the account. A call made
        // outside any execution, or from one without a tenant, draws on
        // the connection's budget either way.
        //
        // Best effort: the check and the count are not one atomic cache
        // operation, so a window can run slightly over under enough
        // concurrency. The provider's own 429 remains the backstop.
        'rate_limit' => null,

        // Paging through a provider's results. max_pages bounds one
        // pagination; reaching it is an error, not a quiet stop, because
        // a short list that looks complete is the worst outcome a
        // paginator can produce. A connection's max_pages overrides it.
        'pagination' => [
            'max_pages' => env('LARAFLOW_HTTP_MAX_PAGES', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | One entry per connector. Each holds up to three keys and nothing
    | else — a setting left anywhere else is refused with the path it
    | should move to, rather than silently read by nothing:
    |
    |   connector    The class implementing Laraflow\Integration\Connector.
    |                Declaring it here registers the connector at boot;
    |                a package may call LaraflowManager::register() from
    |                its own provider instead and omit it.
    |   default      The connection used when a caller names none.
    |                Defaults to "default".
    |   connections  One account per entry: name => settings. The same
    |                connector class serves every connection, so a second
    |                GitHub organisation or a per-environment account is a
    |                second entry here, not a second class.
    |
    | A connection may also set base_url, timeout, connect_timeout, headers,
    | rate_limit, max_pages, api_version, throw_on_error,
    | and retries, overriding the connector class and the `http` section
    | above, and throw_on_error to have request() throw a mapped error
    | rather than return the failed response.
    |
    | Within a connection, `credentials` holds the secrets. They are read
    | when a request is built, not when the connector is constructed, so
    | changing one takes effect on the next request; and they never reach
    | the Connection object a connector holds, so nothing that dumps a
    | connector can print them. Bind your own CredentialProvider to read
    | them from a vault or a per-tenant store instead of from here.
    |
    | Keep credentials in .env, not in a published copy of this file.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The inspection dashboard is off unless the application mounts it by
    | calling Laraflow\Dashboard\Dashboard::routes(); these are the
    | defaults it mounts with. Who may use it is decided by
    | Dashboard::auth(), or a `viewLaraflow` gate, or — with neither — the
    | local environment alone. See docs/dashboard.md.
    |
    */

    'dashboard' => [
        // Where the dashboard answers once mounted.
        'path' => env('LARAFLOW_DASHBOARD_PATH', 'laraflow'),

        // The middleware every dashboard route runs through before the
        // dashboard's own authorization check. `web` supplies the session
        // and CSRF protection the action forms depend on.
        'middleware' => ['web'],

        // Mount a dashboard that can only look: the action routes are
        // absent, not hidden.
        'read_only' => env('LARAFLOW_DASHBOARD_READ_ONLY', false),

        // A gate the four actions (start, execute, cancel, signal) must
        // also pass, on top of the view rule — e.g. 'actOnLaraflow', for
        // viewers who may look but not touch. Null: whoever may view may
        // act. A named gate that is never defined denies every action.
        // Dashboard::authActions() takes precedence. See docs/dashboard.md.
        'action_gate' => env('LARAFLOW_DASHBOARD_ACTION_GATE'),

        // Executions per list page, up to the repository's own ceiling.
        'per_page' => env('LARAFLOW_DASHBOARD_PER_PAGE', 25),

        // Seconds between a page asking for itself again; 0 turns the
        // refresh off. Only with script — without it the page is static
        // and the browser's reload does the same job.
        'refresh' => env('LARAFLOW_DASHBOARD_REFRESH', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Triggers
    |--------------------------------------------------------------------------
    |
    | Signals over HTTP, off unless the application mounts them by calling
    | Laraflow\Triggers\Triggers::routes(); these are the defaults it
    | mounts with. Every request to the signed endpoint is verified
    | against `key` — HMAC-SHA256 over the timestamp, execution id,
    | signal, and body — before anything is read, and there is no
    | environment bypass. Mounting with no key refuses at the mount.
    | See docs/triggers.md.
    |
    */

    'triggers' => [
        // Where the endpoints answer once mounted.
        'path' => env('LARAFLOW_TRIGGERS_PATH', 'laraflow/triggers'),

        // The middleware every trigger route runs through. `api` carries
        // no session — the signature is the whole identity. The throttle
        // (60 a minute, per client IP) is there by default because these
        // routes are public and each request costs an HMAC and, once
        // verified, a database write; replace it with a named limiter
        // (`throttle:laraflow-triggers`) when a signer sends more.
        'middleware' => ['api', 'throttle:60,1'],

        // The largest request body read before verification, in bytes.
        // Larger is refused with 413 before anything is hashed, so an
        // unauthenticated client cannot make the server hash megabytes.
        // A signal payload is a small JSON object; 64 KiB is generous.
        'max_body_bytes' => env('LARAFLOW_TRIGGERS_MAX_BODY_BYTES', 65536),

        // The shared secret the signed endpoint verifies against. At
        // least 32 bytes; `openssl rand -hex 32`. From the environment,
        // never a committed file.
        'key' => env('LARAFLOW_TRIGGERS_KEY'),

        // Keys still accepted during a rotation: set the new key above,
        // list the old one here (comma-separated in the environment),
        // remove it once every signer has moved.
        'previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('LARAFLOW_TRIGGERS_PREVIOUS_KEYS', ''))))),

        // Seconds a request's timestamp may differ from this server's
        // clock, either way, before it is refused as stale.
        'tolerance' => env('LARAFLOW_TRIGGERS_TOLERANCE', 300),
    ],

    'integrations' => [
        'github' => [
            'connector' => Laraflow\Integration\Github\GithubConnector::class,
            'default' => env('LARAFLOW_GITHUB_CONNECTION', 'default'),
            'connections' => [
                'default' => [
                    'credentials' => [
                        'token' => env('LARAFLOW_GITHUB_TOKEN'),
                    ],
                ],
            ],
        ],

        'slack' => [
            'connector' => Laraflow\Integration\Slack\SlackConnector::class,
            'default' => env('LARAFLOW_SLACK_CONNECTION', 'default'),
            'connections' => [
                'default' => [
                    'credentials' => [
                        'token' => env('LARAFLOW_SLACK_TOKEN'),
                    ],
                ],
            ],
        ],
    ],

];
