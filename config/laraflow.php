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
    | whose dispatch was lost) and re-dispatches it.
    |
    | limit: maximum executions handled per run, oldest first.
    |
    | max_attempts: an execution where any step has already been attempted
    | this many times is reported as abandoned instead of re-dispatched, so
    | a crash-looping workflow cannot be retried forever by the scheduler.
    | Abandoned executions can still be re-run manually via laraflow:execute.
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
        'max_attempts' => env('LARAFLOW_RECOVERY_MAX_ATTEMPTS', 3),
        'pending_after' => env('LARAFLOW_RECOVERY_PENDING_AFTER'),
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
