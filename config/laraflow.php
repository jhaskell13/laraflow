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
    */

    'execution' => [
        'stale_after' => env('LARAFLOW_STALE_AFTER', 300),
        'max_error_length' => env('LARAFLOW_MAX_ERROR_LENGTH', 65535),
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
    | retries that have come due — the contractual pickup behind
    | retry.dispatch_delayed, and the only delivery path for in-process
    | and sync-driver runs.
    |
    | limit: maximum executions handled per run, most urgent first.
    |
    | abandon_after_attempts: an *interrupted* execution where any step
    | has already been attempted this many times is reported as abandoned
    | instead of re-dispatched, so a crash-looping workflow cannot be
    | retried forever by the scheduler. This bound is crash-loop
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
    | Integrations
    |--------------------------------------------------------------------------
    |
    | Credentials and settings for the connectors that ship with Laraflow.
    | Keep secrets in your environment file, not in version control.
    |
    */

    'integrations' => [
        'github' => [
            'token' => env('LARAFLOW_GITHUB_TOKEN'),
        ],
    ],

];
