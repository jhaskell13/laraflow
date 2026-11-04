<?php

declare(strict_types=1);

namespace Laraflow\Pulse\Tests;

use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Pulse\PulseServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaraflowServiceProvider::class, PulseServiceProvider::class];
    }
}
