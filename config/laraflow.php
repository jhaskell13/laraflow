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
    */

    'recovery' => [
        'limit' => env('LARAFLOW_RECOVERY_LIMIT', 100),
        'abandon_after_attempts' => env('LARAFLOW_RECOVERY_ABANDON_AFTER_ATTEMPTS', 3),
        'pending_after' => env('LARAFLOW_RECOVERY_PENDING_AFTER'),
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
    | limit: most executions handled per tier per run. chunk: how many
    | are written at once.
    |
    */

    'retention' => [
        'scrub_after_days' => env('LARAFLOW_RETENTION_SCRUB_AFTER_DAYS'),
        'prune_attempts_after_days' => env('LARAFLOW_RETENTION_PRUNE_ATTEMPTS_AFTER_DAYS'),
        'delete_after_days' => env('LARAFLOW_RETENTION_DELETE_AFTER_DAYS'),
        'delete_failed_after_days' => env('LARAFLOW_RETENTION_DELETE_FAILED_AFTER_DAYS'),
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
    */

    'workflows' => [
        'max_depth' => env('LARAFLOW_WORKFLOWS_MAX_DEPTH', 8),
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
