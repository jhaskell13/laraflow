<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * Moves a secret from the workflow input into the context and the step
 * output, so tests can assert lifecycle events never carry any of them.
 */
class SecretLeakStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $context->set('copied_secret', $context->get('secret'));

        return ['secret_output' => $context->get('secret')];
    }
}
