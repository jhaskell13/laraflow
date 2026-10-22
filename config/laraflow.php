<?php

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
