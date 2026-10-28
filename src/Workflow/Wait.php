<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A step's declaration that the execution should park instead of
 * continuing: returned from Step::handle() in place of an output.
 *
 * A wait is the step's outcome boundary. Everything the step did before
 * returning one is checkpointed; when the wait resolves — the instant
 * arrives, or the named signal is delivered — the step completes with
 * the signal's payload (or null for a timed wait) as its output, and
 * handle() never resumes mid-body. Work that belongs after the wait
 * belongs in the next step. The full contract is
 * docs/advanced-workflows.md.
 */
final readonly class Wait
{
    /**
     * Signal names starting with this prefix are Laraflow's own — the
     * sub-workflow machinery parks parents on them. Applications must
     * choose names outside it.
     */
    public const RESERVED_PREFIX = 'laraflow.';

    private function __construct(
        public ?string $signal,
        public ?DateTimeImmutable $resumeAt,
        public ?int $resumeAfterSeconds,
        public ?int $timeoutAfterSeconds = null,
    ) {}

    /** Park until this instant. */
    public static function until(DateTimeImmutable $instant): self
    {
        return new self(null, $instant, null);
    }

    /** Park for this many seconds, measured from the park itself. */
    public static function untilSeconds(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('A timed wait must be at least one second.');
        }

        return new self(null, null, $seconds);
    }

    /**
     * Park until the application delivers this signal. Without a
     * timeout the execution waits indefinitely, and the inspection
     * output says so.
     */
    public static function forSignal(string $name): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A signal name must not be empty.');
        }

        if (str_starts_with($name, self::RESERVED_PREFIX)) {
            throw new InvalidArgumentException(
                'Signal names starting with ['.self::RESERVED_PREFIX.'] are reserved for Laraflow itself.'
            );
        }

        return new self($name, null, null);
    }

    /**
     * @internal The sub-workflow machinery's park on its child, exempt
     * from the reserved-prefix refusal that applies to applications.
     */
    public static function forChild(int|string $childExecutionId): self
    {
        return new self(self::RESERVED_PREFIX.'child.'.$childExecutionId, null, null);
    }

    /**
     * Bound a signal wait: past the deadline the step fails with
     * WaitTimedOutException, charged to its retry policy like any
     * failure. Meaningless on a timed wait, and refused there.
     */
    public function timeoutAfterSeconds(int $seconds): self
    {
        if ($this->signal === null) {
            throw new InvalidArgumentException('A timed wait has no timeout; the resume instant already bounds it.');
        }

        if ($seconds < 1) {
            throw new InvalidArgumentException('A wait timeout must be at least one second.');
        }

        return new self($this->signal, null, null, $seconds);
    }

    /** The instant a timed wait resumes, measured from $now. */
    public function resumeInstant(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->resumeAt !== null) {
            return $this->resumeAt;
        }

        if ($this->resumeAfterSeconds !== null) {
            return $now->add(new DateInterval("PT{$this->resumeAfterSeconds}S"));
        }

        return null;
    }

    /** The instant a signal wait times out, measured from $now. */
    public function timeoutInstant(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $this->timeoutAfterSeconds === null
            ? null
            : $now->add(new DateInterval("PT{$this->timeoutAfterSeconds}S"));
    }
}
