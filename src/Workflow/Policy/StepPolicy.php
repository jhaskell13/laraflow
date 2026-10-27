<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Policy;

use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\PayloadTooLargeException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Throwable;

/**
 * Retry and timeout policy for one step.
 *
 * Resolution order is step → workflow → configuration; see
 * PolicyResolver. The package default is maxAttempts = 1, so nothing is
 * retried until an application opts in.
 *
 * This class is pure: it decides *whether* another attempt is allowed
 * and *how long* to wait, and knows nothing about persistence, queues,
 * or clocks. The runner applies its decisions.
 */
readonly class StepPolicy
{
    /**
     * Exceptions that are never retried, whatever a policy says.
     *
     * Each is a deterministic definition, serialization, or size fault,
     * or a signal that this worker no longer owns the execution:
     * another attempt can only repeat the outcome, so retrying would
     * fill the schedule with work that cannot succeed. (The runner also handles
     * ClaimLostException earlier — a worker without the claim must not
     * persist anything at all — but the policy is correct standalone.)
     *
     * @var list<class-string<Throwable>>
     */
    public const NEVER_RETRY = [
        UnserializableValueException::class,
        PayloadTooLargeException::class,
        WorkflowDefinitionMismatchException::class,
        InvalidWorkflowDefinitionException::class,
        InvalidStateTransitionException::class,
        ClaimLostException::class,
    ];

    /**
     * @param  int  $maxAttempts  Total attempts including the first; 1 disables retries.
     * @param  int  $baseDelaySeconds  Delay after the first failed attempt.
     * @param  float  $multiplier  Exponential backoff factor; 1.0 gives a fixed delay.
     * @param  int  $maxDelaySeconds  Hard upper bound on any delay, jitter included.
     * @param  float  $jitter  Fraction of the delay used as a ± window (0.0–1.0).
     * @param  list<class-string<Throwable>>  $retryOn  Only these types retry; empty means all.
     * @param  list<class-string<Throwable>>  $dontRetryOn  These never retry; wins over $retryOn.
     * @param  int|null  $timeoutSeconds  Deadline for one attempt, or null for none.
     *                                    Laraflow records and exposes the deadline but does not
     *                                    interrupt a running step; see docs.
     */
    public function __construct(
        public int $maxAttempts = 1,
        public int $baseDelaySeconds = 5,
        public float $multiplier = 2.0,
        public int $maxDelaySeconds = 300,
        public float $jitter = 0.2,
        public array $retryOn = [],
        public array $dontRetryOn = [],
        public ?int $timeoutSeconds = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1 (1 disables retries).');
        }

        if ($baseDelaySeconds < 0 || $maxDelaySeconds < 0) {
            throw new InvalidArgumentException('Retry delays cannot be negative.');
        }

        if ($multiplier < 1.0) {
            throw new InvalidArgumentException('multiplier must be at least 1.0; a shrinking backoff is not a backoff.');
        }

        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new InvalidArgumentException('jitter must be a fraction between 0.0 and 1.0.');
        }

        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new InvalidArgumentException('timeoutSeconds must be at least 1 second, or null for no deadline.');
        }
    }

    /**
     * Whether another attempt may run after a failure on $attempt
     * (1-based: the step's `attempts` counter at the time it failed).
     */
    public function shouldRetry(Throwable $exception, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        foreach (self::NEVER_RETRY as $class) {
            if ($exception instanceof $class) {
                return false;
            }
        }

        foreach ($this->dontRetryOn as $class) {
            if ($exception instanceof $class) {
                return false;
            }
        }

        if ($this->retryOn === []) {
            return true;
        }

        foreach ($this->retryOn as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds to wait after a failure on $attempt (1-based).
     *
     * The delay grows as base × multiplier^(attempt-1), capped at
     * maxDelaySeconds, then spread within a ±(jitter × delay) window.
     *
     * When that window would cross the ceiling it is *slid down* to fit
     * rather than clipped against it. Clipping would land every
     * execution whose jitter pointed upward on exactly maxDelaySeconds
     * — roughly half of them, piled on the same instant, in precisely
     * the situation jitter exists to prevent: many executions that have
     * all backed off to the ceiling together.
     *
     * Jitter is derived from $seed rather than a random source, so one
     * logical retry always yields the same delay — reproducible in a
     * test and explainable in production — while different executions
     * still spread apart. The runner seeds it with the execution id and
     * the step position.
     */
    public function delaySeconds(int $attempt, string $seed = ''): int
    {
        $centre = min(
            (float) $this->maxDelaySeconds,
            $this->baseDelaySeconds * ($this->multiplier ** max(0, $attempt - 1)),
        );

        if ($this->jitter <= 0.0 || $centre <= 0.0) {
            return (int) round($centre);
        }

        $half = $this->jitter * $centre;

        if ($centre + $half > $this->maxDelaySeconds) {
            $centre = max(0.0, $this->maxDelaySeconds - $half);
        }

        // crc32 → [0, 1) → [-1, 1), stable for one logical retry.
        $fraction = abs(crc32("{$seed}:{$attempt}") % 10000) / 10000;

        $delay = $centre + ($fraction * 2.0 - 1.0) * $half;

        return (int) max(0, min((float) $this->maxDelaySeconds, round($delay)));
    }

    public function retriesEnabled(): bool
    {
        return $this->maxAttempts > 1;
    }
}
