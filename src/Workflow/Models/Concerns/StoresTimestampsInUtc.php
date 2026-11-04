<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Laraflow\Workflow\Models\UtcQueryBuilder;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Every Laraflow timestamp column holds a UTC wall-clock, whatever
 * `app.timezone` says (docs/compatibility.md, "Stored timestamps").
 *
 * Eloquent's own convention is the application's wall-clock: it
 * formats a value in whatever zone the object carries and reads a
 * column back as `date_default_timezone_get()`. That is ambiguous for
 * one hour a year in any zone with daylight saving — 05:00Z and 06:00Z
 * are both "01:00" in New York on the autumn change — so the schedule
 * ticks' unique key took the second hourly tick for the first and
 * reported it "taken", and every due-time comparison in that hour had
 * two answers. UTC has no repeated hour.
 *
 * Converted at the model boundary, in both directions, for every date
 * attribute — the declared `datetime` casts and Eloquent's own
 * created_at/updated_at alike, since all of them pass through
 * fromDateTime() on the way in and asDateTime() on the way out. What
 * comes out is the same instant rendered in the application's zone, so
 * the handles, events, and displays built from it read exactly as they
 * did. Comparisons and query-builder writes, which no cast sees, are
 * covered by the builder this hands Eloquent (UtcQueryBuilder).
 */
trait StoresTimestampsInUtc
{
    /**
     * A date attribute as the string to store: the instant, in UTC.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function fromDateTime($value)
    {
        if (empty($value)) {
            return $value;
        }

        return $this->asDateTime($value)->copy()->setTimezone('UTC')->format($this->getDateFormat());
    }

    /**
     * A stored string read as the UTC wall-clock it is, handed back in
     * the application's zone. Instances and Unix timestamps are already
     * unambiguous instants and take Eloquent's own path.
     *
     * @param  mixed  $value
     * @return Carbon
     */
    protected function asDateTime($value)
    {
        if (! is_string($value) || is_numeric($value) || $this->isStandardDateFormat($value)) {
            return parent::asDateTime($value);
        }

        try {
            $date = Date::createFromFormat($this->getDateFormat(), $value, 'UTC');
        } catch (InvalidArgumentException) {
            $date = null;
        }

        // A driver that returns fractional seconds, or an engine whose
        // format differs from the grammar's, still names a UTC
        // wall-clock; an explicit offset in the string wins over it.
        $date = $date ?: Date::parse($value, 'UTC');

        return $date->setTimezone(date_default_timezone_get());
    }

    /**
     * @return UtcQueryBuilder
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new UtcQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }
}
