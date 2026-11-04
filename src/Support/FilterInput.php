<?php

declare(strict_types=1);

namespace Laraflow\Support;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laraflow\Workflow\ExecutionStatus;
use Throwable;

/**
 * @internal How a status name and a time typed by a person become an
 * ExecutionStatus and a DateTimeImmutable — the console's options and
 * the dashboard's query string both. One definition, so `--since=-2
 * hours` and `?since=-2 hours` cannot mean different things.
 *
 * What is shared is what a value *means*: comma lists, the leading
 * backslash on a class name, the 20-value cap, an empty time window.
 * What each surface does with a value it cannot use stays its own, on
 * purpose (Milestone 10 audit): the console refuses — an unknown
 * status, a 21st value, or an empty window is a typo in a command that
 * may be about to act on durable records, and acting on part of what
 * was typed is worse than stopping — while the dashboard reports the
 * problem and drops the field, because an operator who mistyped one
 * filter on a page still wants the others to hold.
 */
final class FilterInput
{
    /**
     * How many values one repeatable filter may carry, on either
     * surface. Past it the console refuses and the dashboard drops the
     * extras and says so; see FilterRequest for why a bound exists.
     */
    public const MAX_VALUES = 20;

    /**
     * Raw repeatable input — `--status=failed --status=retrying`,
     * `--status=failed,retrying`, `?status[]=…`, `?status=a,b` — as one
     * flat, trimmed, de-duplicated list. Uncapped: what to do past
     * MAX_VALUES is the caller's policy.
     *
     * @param  array<mixed>  $raw
     * @return list<string>
     */
    public static function values(array $raw): array
    {
        $out = [];

        foreach ($raw as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            foreach (explode(',', (string) $item) as $piece) {
                $piece = trim($piece);

                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A workflow class as typed. A leading backslash is how PHP writes
     * a fully qualified name (`\App\Workflows\Billing`) and how a
     * copy from source arrives, but the column stores `App\Workflows\…`
     * — matched literally, the filter found nothing and said nothing.
     */
    public static function workflow(string $workflow): string
    {
        return ltrim(trim($workflow), '\\');
    }

    /**
     * ExecutionFilter refuses an empty created window in its own
     * vocabulary — createdAfter, createdBefore — which is right for a
     * PHP caller and meaningless to someone who typed `--since`. Each
     * surface asks here first, naming the fields as it spells them.
     *
     * @throws InvalidArgumentException
     */
    public static function assertWindow(?DateTimeImmutable $since, ?DateTimeImmutable $until, string $sinceName, string $untilName): void
    {
        if ($since !== null && $until !== null && $since >= $until) {
            throw new InvalidArgumentException(
                "{$sinceName} must be earlier than {$untilName}; the window is empty ({$sinceName} is inclusive, {$untilName} exclusive)."
            );
        }
    }

    /**
     * @throws InvalidArgumentException naming the valid statuses.
     */
    public static function status(string $status): ExecutionStatus
    {
        return ExecutionStatus::tryFrom(trim($status)) ?? throw new InvalidArgumentException(sprintf(
            'Unknown status [%s]. Valid: %s.',
            $status,
            self::statusList(),
        ));
    }

    /**
     * Absolute times and relative expressions alike, so `-2 hours`
     * works as an operator expects.
     *
     * @param  string  $name  How the caller refers to the field in the message.
     *
     * @throws InvalidArgumentException when the value is not a time.
     */
    public static function time(string $value, string $name): DateTimeImmutable
    {
        try {
            return Carbon::parse(trim($value))->toImmutable();
        } catch (Throwable) {
            throw new InvalidArgumentException(
                "The {$name} value must be a date or time, got [{$value}]. "
                .'Try an absolute time (2026-10-24 09:00:00) or a relative one (-2 hours).'
            );
        }
    }

    /**
     * A count typed by a person — a --limit, a number of days — as a
     * non-negative int, or null when it is not one. Digits only:
     * is_numeric() let 1.5 through as 1 and 1e2 as 100, numbers nobody
     * typed (Milestone 10 audit). An int is accepted as-is because
     * Artisan::call() may hand one over programmatically.
     */
    public static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Listed from the enum rather than written out, so help text and
     * error messages cannot drift from the cases that exist.
     */
    public static function statusList(): string
    {
        return implode(', ', array_column(ExecutionStatus::cases(), 'value'));
    }
}
