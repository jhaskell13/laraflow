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
