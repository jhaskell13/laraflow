<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * One execution recorded more distinct idempotency keys than the
 * configured cap allows. A loop calling remember() with a generated
 * key would otherwise grow a table without bound. Never retried.
 */
class TooManyIdempotencyKeysException extends WorkflowException
{
    public static function forExecution(int $executionId, int $max): self
    {
        return new self(sprintf(
            'Execution [%d] has recorded %d idempotency keys, the configured maximum (laraflow.idempotency.max_keys_per_execution). '
            .'A step remembering under a generated key is usually a loop; raise the cap deliberately if the workflow needs more.',
            $executionId,
            $max,
        ));
    }
}
