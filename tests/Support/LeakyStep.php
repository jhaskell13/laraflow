<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * Fails with a secret in the exception message, the way a real HTTP
 * client exception embeds the request that carried a credential.
 */
class LeakyStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        throw new RuntimeException('provider rejected token tok_SENTINEL123 for customer 42');
    }
}
