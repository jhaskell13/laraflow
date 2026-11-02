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
 */
final class FilterInput
{
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
     * Listed from the enum rather than written out, so help text and
     * error messages cannot drift from the cases that exist.
     */
    public static function statusList(): string
    {
        return implode(', ', array_column(ExecutionStatus::cases(), 'value'));
    }
}
