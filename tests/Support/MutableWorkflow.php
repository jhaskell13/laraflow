<?php

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

class MutableWorkflow extends Workflow
{
    /** @var string[] */
    public static array $steps = [];

    public function steps(): array
    {
        return static::$steps;
    }
}
