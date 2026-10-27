<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

class MutableWorkflow extends Workflow
{
    /** @var list<class-string<\Laraflow\Workflow\Step>> */
    public static array $steps = [];

    public function steps(): array
    {
        return static::$steps;
    }
}
