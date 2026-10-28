<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Throwable;

/**
 * @internal How a claimed, parked step resolved — the runner's loop
 * either continues past the step, carries a continue-policy failure
 * forward, or is finished with the execution for this claim.
 */
final readonly class WaitResolution
{
    private function __construct(
        public bool $resumed,
        public ?Throwable $continueFailure,
    ) {}

    /** The step completed; the loop continues to the next one. */
    public static function resumed(): self
    {
        return new self(true, null);
    }

    /**
     * This claim is finished with the execution: parked again, retry
     * scheduled, or failed terminally. The handle describes the rest.
     */
    public static function settled(): self
    {
        return new self(false, null);
    }

    /**
     * The step failed under FailurePolicy::Continue; the loop keeps
     * going and the execution fails with this once everything ran.
     */
    public static function continueWith(Throwable $failure): self
    {
        return new self(false, $failure);
    }
}
