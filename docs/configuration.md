# Configuration

What `php artisan laraflow:install` publishes, and what each section of `config/laraflow.php` controls.

Publish the configuration file if you need to change defaults:

```bash
php artisan vendor:publish --tag=laraflow-config
```

This creates `config/laraflow.php` (abridged here — the shipped file also has the `idempotency`, `schedules`, `tenants`, `dashboard`, and `triggers` sections, `recovery.redispatch_after`, `retention.prune_ticks_after_days`, `triggers.max_body_bytes`, `dashboard.action_gate`, the bundled Slack connector, the `workflows.max_depth` and `workflows.max_members` bounds on sub-workflow nesting and fan-out width, and a documenting comment block per section; [`config/laraflow.php`](../config/laraflow.php) is the complete reference):

```php
return [
    'migrations' => [
        // Load migrations from the package (true) or manage published copies (false).
        'load' => env('LARAFLOW_LOAD_MIGRATIONS', true),
    ],

    'execution' => [
        // Seconds before a running execution's claim is considered abandoned
        // and may be reclaimed. Set it above your longest step.
        'stale_after' => env('LARAFLOW_STALE_AFTER', 300),
        'max_error_length' => env('LARAFLOW_MAX_ERROR_LENGTH', 65535),
        // Largest a single input, output, or context snapshot may be, as
        // JSON; null leaves the only limit the database's.
        'max_payload_bytes' => env('LARAFLOW_MAX_PAYLOAD_BYTES'),
    ],

    'retry' => [
        // Default step retry policy. 1 counts the first attempt, so the
        // shipped value means nothing is retried until you opt in.
        'max_attempts' => env('LARAFLOW_RETRY_MAX_ATTEMPTS', 1),
        'base_delay' => env('LARAFLOW_RETRY_BASE_DELAY', 5),
        'multiplier' => env('LARAFLOW_RETRY_MULTIPLIER', 2.0),
        'max_delay' => env('LARAFLOW_RETRY_MAX_DELAY', 300),
        'jitter' => env('LARAFLOW_RETRY_JITTER', 0.2),
        // Deliver due retries via a delayed queue job; laraflow:recover
        // remains the safety net either way.
        'dispatch_delayed' => env('LARAFLOW_RETRY_DISPATCH_DELAYED', true),
        // Cap on a retry delay an exception suggests (a provider's Retry-After).
        'max_suggested_delay' => env('LARAFLOW_RETRY_MAX_SUGGESTED_DELAY', 3600),
    ],

    'timeout' => [
        // Default per-attempt step deadline in seconds; null = none.
        'step' => env('LARAFLOW_STEP_TIMEOUT'),
    ],

    'errors' => [
        // Optional ErrorRedactor class applied to every exception message
        // Laraflow persists, logs, or emits.
        'redactor' => env('LARAFLOW_ERROR_REDACTOR'),
    ],

    'queue' => [
        // null uses your application's default connection and queue.
        'connection' => env('LARAFLOW_QUEUE_CONNECTION'),
        'queue' => env('LARAFLOW_QUEUE'),
        // 1 = the queue does not retry; see docs/queue-and-recovery.md.
        'tries' => env('LARAFLOW_QUEUE_TRIES', 1),
        'backoff' => env('LARAFLOW_QUEUE_BACKOFF', 0),
        // Must stay below execution.stale_after.
        'timeout' => env('LARAFLOW_QUEUE_TIMEOUT', 60),
        'after_commit' => env('LARAFLOW_QUEUE_AFTER_COMMIT', true),
    ],

    'recovery' => [
        'limit' => env('LARAFLOW_RECOVERY_LIMIT', 100),
        // Interrupted executions whose steps reach this many attempts — or
        // that were claimed this many times without progress — are
        // abandoned by recovery rather than retried forever.
        'abandon_after_attempts' => env('LARAFLOW_RECOVERY_ABANDON_AFTER_ATTEMPTS', 3),
        // null disables recovery of never-dispatched pending executions.
        'pending_after' => env('LARAFLOW_RECOVERY_PENDING_AFTER'),
    ],

    'retention' => [
        // All null: nothing is removed until you say what to keep. Days,
        // counted from when the execution finished. See docs/retention.md.
        'scrub_after_days' => env('LARAFLOW_RETENTION_SCRUB_AFTER_DAYS'),
        'prune_attempts_after_days' => env('LARAFLOW_RETENTION_PRUNE_ATTEMPTS_AFTER_DAYS'),
        'delete_after_days' => env('LARAFLOW_RETENTION_DELETE_AFTER_DAYS'),
        // Failed executions can still be recovered, so they have their own bound.
        'delete_failed_after_days' => env('LARAFLOW_RETENTION_DELETE_FAILED_AFTER_DAYS'),
        'limit' => env('LARAFLOW_RETENTION_LIMIT', 1000),
        'chunk' => env('LARAFLOW_RETENTION_CHUNK', 100),
    ],

    'logging' => [
        // Put the running execution's identity (laraflow.* keys) into
        // Laravel's log context while a step runs.
        'context' => env('LARAFLOW_LOG_CONTEXT', true),
        // One log record per lifecycle event; off because an application
        // already listening to events should not also pay for a line per step.
        'lifecycle' => env('LARAFLOW_LOG_LIFECYCLE', false),
    ],

    'events' => [
        // Lifecycle events never carry input, output, or context. This
        // controls only the exception message on failure events.
        'include_error_messages' => env('LARAFLOW_EVENT_ERROR_MESSAGES', true),
    ],

    'http' => [
        // Defaults every connection starts from; a connector class and then
        // a connection may override them.
        'timeout' => env('LARAFLOW_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('LARAFLOW_HTTP_CONNECT_TIMEOUT', 10),
        // In-attempt wire retries: off. The step's retry policy is the
        // durable loop; this one holds the worker, so it stays short.
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
        // A per-connection ceiling Laraflow holds itself to: null, or
        // ['max' => 60, 'per' => 60, 'max_wait' => 0].
        'rate_limit' => null,
        // Reaching this is an error, not a quiet stop.
        'pagination' => ['max_pages' => env('LARAFLOW_HTTP_MAX_PAGES', 100)],
    ],

    'integrations' => [
        // One entry per connector: the class, the default connection, and
        // one named connection per account. Nothing else is accepted here.
        'github' => [
            'connector' => \Laraflow\Integration\Github\GithubConnector::class,
            'default' => env('LARAFLOW_GITHUB_CONNECTION', 'default'),
            'connections' => [
                'default' => [
                    // Read when a request is built, never held by the connector.
                    'credentials' => [
                        'token' => env('LARAFLOW_GITHUB_TOKEN'),
                    ],
                ],
            ],
        ],
    ],
];
```

Keep credentials in your `.env` file, not in the published config.
