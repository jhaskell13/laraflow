<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * A workflow's declared cadence — what `Workflow::schedule()` returns.
 *
 * A declaration, not an activation: the workflow runs on this schedule
 * only once the application lists the class under
 * `laraflow.schedules.workflows` (or a package registers it through the
 * `ScheduleRegistry`). The declaration is validated where it is built,
 * so an unparseable expression or an unknown timezone is refused at the
 * declaration site rather than discovered at tick time.
 *
 * Immutable: every method returns a new instance, so a schedule built
 * conditionally cannot half-apply. See docs/schedules.md.
 */
final readonly class Schedule
{
    /**
     * @param  (Closure(DateTimeImmutable): mixed)|array<string, mixed>|null  $input  A callback's
     *                                                                                return is declared mixed because nothing enforces a docblock:
     *                                                                                inputFor() checks at run time and refuses by name.
     */
    private function __construct(
        public string $expression,
        public ?string $timezone,
        private Closure|array|null $input,
        public MissedTickPolicy $missedTickPolicy,
        public bool $withoutOverlapping,
        public ?string $tenantId,
    ) {}

    /**
     * A standard five-field cron expression (or an `@alias` such as
     * `@daily`). A seconds field is refused: a tick is a minute,
     * because the scheduler that runs it is.
     *
     * @throws InvalidArgumentException naming the expression and the rule it broke.
     */
    public static function cron(string $expression): self
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw new InvalidArgumentException('A schedule needs a cron expression.');
        }

        try {
            // Parse, then prove it matches a real instant: the library
            // accepts "0 0 30 2 *" (February 30th) at parse time and
            // throws from every date computation after it — which would
            // otherwise be at tick time, in the scheduler, the listing,
            // and the summary at once.
            (new CronExpression($expression))->getNextRunDate('now', 0, false, 'UTC');
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException(
                "[{$expression}] parses as a cron expression but never matches a real date (".$exception->getMessage().').',
                previous: $exception,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                "[{$expression}] is not a cron expression a schedule can run (".$exception->getMessage().'). '
                .'Five fields — minute, hour, day of month, month, day of week — or an @alias; '
                .'a seconds field is refused because a tick is a minute.',
                previous: $exception,
            );
        }

        return new self($expression, null, null, MissedTickPolicy::Skip, false, null);
    }

    /**
     * The zone the expression is read in; the application's
     * `app.timezone` when never called. Tick instants are stored in UTC
     * either way — the zone decides what "02:00" means, not how the
     * instant is recorded.
     */
    public function timezone(string $timezone): self
    {
        try {
            new DateTimeZone($timezone);
        } catch (Exception $exception) {
            throw new InvalidArgumentException("[{$timezone}] is not a timezone PHP recognises.", previous: $exception);
        }

        return new self($this->expression, $timezone, $this->input, $this->missedTickPolicy, $this->withoutOverlapping, $this->tenantId);
    }

    /**
     * The execution's input, built from the tick instant (UTC) — so a
     * nightly job knows which night it is for without reading the
     * clock, which under catch-up would be the wrong night. A static
     * array is accepted for input that does not depend on the tick.
     *
     * @param  (Closure(DateTimeImmutable): mixed)|array<string, mixed>  $input
     */
    public function input(Closure|array $input): self
    {
        return new self($this->expression, $this->timezone, $input, $this->missedTickPolicy, $this->withoutOverlapping, $this->tenantId);
    }

    public function onMissedTicks(MissedTickPolicy $policy): self
    {
        return new self($this->expression, $this->timezone, $this->input, $policy, $this->withoutOverlapping, $this->tenantId);
    }

    /**
     * Do not start a tick's execution while the previous tick's is
     * unfinished; the tick is still claimed, recorded as skipped, and
     * names what it waited on. Off by default: most scheduled work is
     * independent per tick, and skipping is a decision an author should
     * take knowingly.
     */
    public function withoutOverlapping(bool $withoutOverlapping = true): self
    {
        return new self($this->expression, $this->timezone, $this->input, $this->missedTickPolicy, $withoutOverlapping, $this->tenantId);
    }

    /**
     * Record this tenant on every execution the schedule starts. One
     * schedule serves one tenant or none; per-tenant work fans out
     * *inside* the execution (docs/schedules.md, "Declaring a schedule").
     */
    public function tenant(string $tenantId): self
    {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('A schedule tenant cannot be blank; omit tenant() for none.');
        }

        // The same rules every application-supplied identifier meets —
        // trimmed, bounded, no control characters — applied by the one
        // validator that owns them.
        $options = new ExecutionOptions(tenantId: $tenantId);

        return new self($this->expression, $this->timezone, $this->input, $this->missedTickPolicy, $this->withoutOverlapping, $options->tenantId);
    }

    /**
     * @internal How the scheduler resolves input() for one tick.
     *
     * The input the execution for $tick starts with.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when a callback returns something that is not an array.
     */
    public function inputFor(DateTimeImmutable $tick): array
    {
        if ($this->input === null) {
            return [];
        }

        if (is_array($this->input)) {
            return $this->input;
        }

        $input = ($this->input)($tick);

        if (! is_array($input)) {
            throw new InvalidArgumentException(
                'A schedule input callback must return an array to seed the context, got '.get_debug_type($input).'.'
            );
        }

        return $input;
    }
}
