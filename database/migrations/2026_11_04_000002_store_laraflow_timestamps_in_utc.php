<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Laraflow timestamp column, by table, with whether it may be
     * null — the shape the shipped migrations gave each one, which a
     * MySQL column change must restate in full.
     *
     * @var array<string, array<string, bool>>
     */
    private const COLUMNS = [
        'laraflow_workflow_executions' => [
            'claimed_at' => true, 'started_at' => true, 'completed_at' => true, 'failed_at' => true,
            'dispatched_at' => true, 'available_at' => true, 'finished_at' => true,
            'payloads_scrubbed_at' => true, 'next_retry_at' => true, 'cancel_requested_at' => true,
            'cancelled_at' => true, 'deferred_until' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'laraflow_step_executions' => [
            'started_at' => true, 'completed_at' => true, 'failed_at' => true, 'timeout_at' => true,
            'next_retry_at' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'laraflow_step_attempts' => [
            'started_at' => true, 'ended_at' => true, 'next_retry_at' => true,
            'created_at' => true, 'updated_at' => true,
        ],
        'laraflow_idempotency_keys' => [
            'started_at' => false, 'settled_at' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'laraflow_schedules' => [
            'last_tick_at' => true, 'paused_at' => true, 'declared_at' => true,
            'created_at' => true, 'updated_at' => true,
        ],
        'laraflow_schedule_ticks' => [
            'tick_at' => false, 'missed_from' => true, 'created_at' => true,
        ],
        'laraflow_signal_inbox' => [
            'received_at' => false,
        ],
    ];

    /**
     * Zones with no offset and no daylight saving: stored values are
     * already UTC, and there is nothing to convert.
     */
    private const UTC_ZONES = ['UTC', 'Etc/UTC', 'Etc/UCT', 'UCT', 'Etc/GMT', 'GMT', 'Etc/Universal', 'Universal', 'Etc/Zulu', 'Zulu', 'Z', '+00:00'];

    private const CHUNK = 500;

    /**
     * Every Laraflow timestamp, stored as UTC (docs/compatibility.md,
     * "Stored timestamps").
     *
     * Until now Laraflow stored Eloquent's convention: the application
     * timezone's wall-clock, with no offset. In any zone with daylight
     * saving that is ambiguous for one hour a year — 05:00Z and 06:00Z
     * are both 01:00 in New York on the autumn change — so the schedule
     * ticks' unique (schedule_id, tick_at) key refused the second of two
     * hourly ticks as already taken, and every due-time comparison in
     * that hour had two answers. The package now writes and compares
     * UTC; this migration brings existing rows and columns along.
     *
     * Two parts, in this order:
     *
     * 1. On MySQL and MariaDB, `timestamp` columns become `datetime`
     *    (precision kept at 0). A `timestamp` is converted through the
     *    session time zone on every read and write — so a UTC value
     *    written through a New York session lands shifted, and one
     *    inside that session's spring-forward gap is refused or moved —
     *    and it overflows in January 2038. A `datetime` stores the
     *    wall-clock it is given. The change keeps each value's string
     *    as the session renders it, which is exactly what the package
     *    wrote, so it runs before the conversion. SQLite, PostgreSQL
     *    (`timestamp without time zone`), and SQL Server (`datetime2`)
     *    already store a plain wall-clock and are not altered.
     *
     * 2. Existing values are rewritten from `app.timezone` wall-clock to
     *    UTC — skipped entirely when `app.timezone` is UTC, the common
     *    case, where they already are. Otherwise in PHP, keyed by id in
     *    chunks, inside one transaction so a failure leaves nothing half
     *    converted. A value inside the repeated autumn hour is ambiguous
     *    by construction and is read as its first (daylight) occurrence;
     *    no conversion can do better.
     *
     * Not safe against live workers in a non-UTC application: a row a
     * worker writes during the conversion, in the new convention, would
     * be converted a second time. Stop workers and the scheduler first
     * (docs/upgrading.md). The rollback is the exact reverse, and is
     * refused by MySQL for any value past 2038-01-19, which a
     * `timestamp` cannot hold.
     */
    public function up(): void
    {
        $this->retype(datetime: true);

        $zone = $this->applicationZone();

        if ($zone !== null) {
            $this->shift(from: $zone, to: new DateTimeZone('UTC'));
        }
    }

    public function down(): void
    {
        $zone = $this->applicationZone();

        if ($zone !== null) {
            $this->shift(from: new DateTimeZone('UTC'), to: $zone);
        }

        $this->retype(datetime: false);
    }

    /**
     * The zone stored values were written in, or null when it is UTC
     * and they need no conversion.
     */
    private function applicationZone(): ?DateTimeZone
    {
        $name = config('app.timezone');

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $zone = new DateTimeZone($name);

        return in_array($zone->getName(), self::UTC_ZONES, true) ? null : $zone;
    }

    private function retype(bool $datetime): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $datetime): void {
                foreach ($columns as $column => $nullable) {
                    $definition = $datetime ? $blueprint->dateTime($column) : $blueprint->timestamp($column);
                    $definition->nullable($nullable)->change();
                }
            });
        }
    }

    private function shift(DateTimeZone $from, DateTimeZone $to): void
    {
        $connection = Schema::getConnection();
        $format = $connection->getQueryGrammar()->getDateFormat();

        $connection->transaction(function () use ($connection, $format, $from, $to): void {
            foreach (self::COLUMNS as $table => $columns) {
                $names = array_keys($columns);

                $connection->table($table)
                    ->select(array_merge(['id'], $names))
                    ->chunkById(self::CHUNK, function ($rows) use ($connection, $table, $names, $format, $from, $to): void {
                        foreach ($rows as $row) {
                            $values = [];

                            foreach ($names as $column) {
                                $value = $row->{$column};

                                if ($value !== null) {
                                    $values[$column] = (new DateTimeImmutable((string) $value, $from))
                                        ->setTimezone($to)
                                        ->format($format);
                                }
                            }

                            if ($values !== []) {
                                $connection->table($table)->where('id', $row->id)->update($values);
                            }
                        }
                    });
            }
        });
    }
};
