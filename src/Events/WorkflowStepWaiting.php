<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;

/**
 * A step returned a Wait and the execution durably parked, its claim
 * released. Fired after the park's durable write.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, context, or signal payloads.
 */
readonly class WorkflowStepWaiting
{
    /**
     * @param  string|null  $signal  The signal being waited for, or null for a timed wait.
     * @param  DateTimeImmutable|null  $resumeAt  When a timed wait resumes; null for a signal wait.
     * @param  DateTimeImmutable|null  $timeoutAt  A signal wait's deadline; null means indefinite.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public string $step,
        public int $position,
        public ?string $signal,
        public ?DateTimeImmutable $resumeAt,
        public ?DateTimeImmutable $timeoutAt,
    ) {}
}
