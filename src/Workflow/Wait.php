<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\InvalidSignalException;
use Laraflow\Support\SafeIdentifier;

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

    /** The reserved signal a sub-workflow parent parks on, followed by the child's id. */
    public const CHILD_PREFIX = self::RESERVED_PREFIX.'child.';

    /** The reserved signal a fan-out parks on, followed by its own step row's id. */
    public const JOIN_PREFIX = self::RESERVED_PREFIX.'join.';

    /** The `wait_signal` column is a string(255); a longer name could not be matched back. */
    public const MAX_SIGNAL_LENGTH = 255;

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
        self::assertApplicationSignal($name);

        return new self($name, null, null);
    }

    /**
     * @internal Reserved-signal machinery; applications use forSignal().
     *
     * The one rule for signal names an application chooses, applied
     * where a wait is declared and where a signal is delivered:
     * non-empty, short enough for the column, and outside the reserved
     * prefix in any casing.
     *
     * @throws InvalidSignalException
     */
    public static function assertApplicationSignal(string $name): void
    {
        if (trim($name) === '') {
            throw InvalidSignalException::empty();
        }

        // The same guard the execution identifiers beside this one get:
        // a signal name is persisted, logged, put on an event, and
        // rendered into JSON, so it is held to the same rule rather
        // than to a weaker one (SafeIdentifier).
        $refusal = match (SafeIdentifier::reasonFor($name, self::MAX_SIGNAL_LENGTH)) {
            SafeIdentifier::INVALID_ENCODING => InvalidSignalException::invalidEncoding(),
            SafeIdentifier::CONTROL_CHARACTERS => InvalidSignalException::controlCharacters(),
            SafeIdentifier::TOO_LONG => InvalidSignalException::tooLong(mb_strlen($name)),
            default => null,
        };

        if ($refusal !== null) {
            throw $refusal;
        }

        if (str_starts_with(mb_strtolower($name), self::RESERVED_PREFIX)) {
            throw InvalidSignalException::reserved($name);
        }
    }

    /**
     * @internal The sub-workflow machinery's park on its child, exempt
     * from the reserved-prefix refusal that applies to applications.
     */
    public static function forChild(int $childExecutionId): self
    {
        return new self(self::CHILD_PREFIX.$childExecutionId, null, null);
    }

    /**
     * @internal Machinery: Whether a stored signal name is a sub-workflow parent's park on its child.
     */
    public static function isChildSignal(?string $signal): bool
    {
        return $signal !== null && str_starts_with($signal, self::CHILD_PREFIX);
    }

    /**
     * @internal The fan-out machinery's park on its join: named by the
     * step row, since the members are found by it rather than listed.
     */
    public static function forJoin(int $stepExecutionId): self
    {
        return new self(self::JOIN_PREFIX.$stepExecutionId, null, null);
    }

    /**
     * @internal Machinery: Whether a stored signal name is a fan-out's park on its join.
     */
    public static function isJoinSignal(?string $signal): bool
    {
        return $signal !== null && str_starts_with($signal, self::JOIN_PREFIX);
    }

    /**
     * @internal Reserved-signal machinery; applications use forSignal().
     *
     * The step row id a join signal names; 0 for anything forJoin()
     * did not write, as childIdFrom() reads a malformed child signal.
     */
    public static function joinStepIdFrom(string $signal): int
    {
        $id = substr($signal, strlen(self::JOIN_PREFIX));

        return ctype_digit($id) && (string) (int) $id === $id ? (int) $id : 0;
    }

    /**
     * @internal Machinery: Whether a stored signal is one the package parks on itself — a child's or a join's.
     */
    public static function isMachinerySignal(?string $signal): bool
    {
        return self::isChildSignal($signal) || self::isJoinSignal($signal);
    }

    /**
     * @internal Reserved-signal machinery; applications use forSignal().
     *
     * The child execution id a child signal names. A signal forChild()
     * did not write — anything but digits after the prefix — reads as
     * 0, an id no execution can have, so a lookup reports it missing
     * rather than guessing.
     */
    public static function childIdFrom(string $signal): int
    {
        $id = substr($signal, strlen(self::CHILD_PREFIX));

        return ctype_digit($id) && (string) (int) $id === $id ? (int) $id : 0;
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

    /**
     * @internal Machinery: The instant a timed wait resumes, measured from $now.
     */
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

    /**
     * @internal Machinery: The instant a signal wait times out, measured from $now.
     */
    public function timeoutInstant(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $this->timeoutAfterSeconds === null
            ? null
            : $now->add(new DateInterval("PT{$this->timeoutAfterSeconds}S"));
    }
}
