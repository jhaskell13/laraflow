<?php

declare(strict_types=1);

use Laraflow\Dashboard\Dashboard;

/*
|--------------------------------------------------------------------------
| The playground's dashboard
|--------------------------------------------------------------------------
|
| Mounts the inspection dashboard at /laraflow for `vendor/bin/testbench
| serve`, so the executions the playground's demo and traffic generator
| create can be watched in a browser as well as with playground:watch.
|
| The playground has no users to authorise, so everyone is allowed — a
| rule an application should never ship. See docs/dashboard.md for the
| ones it should.
|
*/

Dashboard::auth(fn (): bool => true);

Dashboard::routes();
