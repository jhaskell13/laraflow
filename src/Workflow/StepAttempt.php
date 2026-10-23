<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;

/**
 * One finished attempt of a step, destined for the append-only history
 * (`laraflow_step_attempts`).
 *
 * The step row always shows its *latest* attempt; these records are why
 * a retry does not erase the diagnostics of the attempts it replaces.
 * They are written in the same transaction as the step state change
 * they describe (see ExecutionRepository::saveStep()/checkpoint()), so
 * history can never disagree with the step row.
 */
readonly class StepAttempt
{
    public function __construct(
        public int|string $executionId,
        public int|string $stepExecutionId,
        public int $attempt,
        public StepAttemptStatus $status,
        public ?string $error = null,
        public ?string $errorClass = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $endedAt = null,
        public ?DateTimeImmutable $nextRetryAt = null,
    ) {}

    /**
     * Wall-clock duration of the attempt in milliseconds, when both
     * ends are known. An interrupted attempt has no end: the worker
     * died, so nothing recorded one.
     */
    public function durationMs(): ?int
    {
        if ($this->startedAt === null || $this->endedAt === null) {
            return null;
        }

        $ms = ((float) $this->endedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000;

        return max(0, (int) round($ms));
    }
}
