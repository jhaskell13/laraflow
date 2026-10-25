<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A durable payload — workflow input, a step's output, or the context
 * snapshot — exceeded `laraflow.execution.max_payload_bytes`.
 *
 * The limit is off by default. When set, it is enforced *before* the
 * write, so an oversized payload fails with this message naming the
 * boundary rather than as a driver error about a column or a packet
 * size, which is the same failure reported from much further away.
 *
 * Never retried: the same step producing the same output will produce
 * the same size, so another attempt could only repeat the outcome.
 */
class PayloadTooLargeException extends WorkflowException
{
    public static function at(string $path, int $bytes, int $maxBytes): self
    {
        return new self(sprintf(
            'Durable value at [%s] is %s bytes, which exceeds the configured limit of %s '
            .'(laraflow.execution.max_payload_bytes). Persist an identifier and reload the data '
            .'where it is needed, or raise the limit if your storage can carry it.',
            $path,
            number_format($bytes),
            number_format($maxBytes),
        ));
    }
}
