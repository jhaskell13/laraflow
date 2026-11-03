<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Throwable;

/**
 * @internal How a claimed step's wait or failure resolved, as the
 * runner's loop needs to know it: continue past the step, carry a
 * continue-policy failure forward, or stop — this claim is finished
 * with the execution. A stop that parked the execution says so, so the
 * runner can honour a cancellation requested across the park.
 */
final readonly class StepResolution
{
    private function __construct(
        public bool $resumed,
        public ?Throwable $continueFailure,
        public bool $parked = false,
        public ?Throwable $terminalFailure = null,
        public bool $retryScheduled = false,
    ) {}

    /** The step completed; the loop continues to the next one. */
    public static function resumed(): self
    {
        return new self(true, null);
    }

    /**
     * This claim is finished with the execution: a retry was durably
     * scheduled and the claim released. The handle describes the
     * schedule.
     */
    public static function settled(): self
    {
        return new self(false, null, false, null, true);
    }

    /**
     * The step failed terminally under FailurePolicy::Stop. The step
     * row records it; the *execution* does not yet, because what
     * happens between the two is the compensation pass — so this is
     * returned rather than thrown, and the runner finishes it.
     */
    public static function failedWith(Throwable $failure): self
    {
        return new self(false, null, false, $failure);
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
