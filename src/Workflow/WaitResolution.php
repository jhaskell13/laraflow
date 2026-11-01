<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Throwable;

/**
 * @internal How a claimed step's wait or failure resolved — the
 * runner's loop either continues past the step, carries a
 * continue-policy failure forward, or is finished with the execution
 * for this claim; and if finished by parking it, honours a pending
 * cancellation first.
 */
final readonly class WaitResolution
{
    private function __construct(
        public bool $resumed,
        public ?Throwable $continueFailure,
        public bool $parked = false,
    ) {}

    /** The step completed; the loop continues to the next one. */
    public static function resumed(): self
    {
        return new self(true, null);
    }

    /**
     * This claim is finished with the execution: a retry was scheduled
     * or the execution failed terminally. The handle describes the rest.
     */
    public static function settled(): self
    {
        return new self(false, null);
    }

    /**
     * This claim is finished with the execution because it parked again
     * and released the claim; a cancellation requested across the park
     * is the runner's to honour.
     */
    public static function parked(): self
    {
        return new self(false, null, true);
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
