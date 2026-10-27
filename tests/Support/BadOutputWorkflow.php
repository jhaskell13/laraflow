<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

class BadOutputWorkflow extends Workflow
{
    public function steps(): array
    {
        return [BadOutputStep::class];
    }
}
