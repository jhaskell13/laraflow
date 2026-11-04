<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Tests;

use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Prometheus\PrometheusServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaraflowServiceProvider::class, PrometheusServiceProvider::class];
    }
}
