<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Laraflow\Workflow\MissedTickPolicy;
use Laraflow\Workflow\Schedule;
use Laraflow\Workflow\Scheduling\ScheduledWorkflow;
use Laraflow\Workflow\Scheduling\ScheduleStatus;
use Laraflow\Workflow\Scheduling\Ticks;
use PHPUnit\Framework\TestCase;

/**
 * docs/schedules.md, "Declaring a schedule": the declaration is
 * validated where it is built, and the tick arithmetic is a pure
 * function of the expression, the timezone, and the clock.
 */
class ScheduleDeclarationTest extends TestCase
{
    public function test_a_declaration_carries_its_choices_and_defaults(): void
    {
        $schedule = Schedule::cron('0 2 * * *');

        $this->assertSame('0 2 * * *', $schedule->expression);
        $this->assertNull($schedule->timezone);
        $this->assertSame(MissedTickPolicy::Skip, $schedule->missedTickPolicy);
        $this->assertFalse($schedule->withoutOverlapping);
        $this->assertNull($schedule->tenantId);
        $this->assertSame([], $schedule->inputFor(new DateTimeImmutable('2026-11-03 02:00:00')));

        $built = $schedule
            ->timezone('Europe/London')
            ->onMissedTicks(MissedTickPolicy::CatchUp)
            ->withoutOverlapping()
            ->tenant('  acme  ');

        $this->assertSame('Europe/London', $built->timezone);
        $this->assertSame(MissedTickPolicy::CatchUp, $built->missedTickPolicy);
        $this->assertTrue($built->withoutOverlapping);
        $this->assertSame('acme', $built->tenantId, 'Identifiers are trimmed, as everywhere.');

        // Immutable: the original is untouched by everything above.
        $this->assertNull($schedule->timezone);
        $this->assertFalse($schedule->withoutOverlapping);
    }

    public function test_an_unparseable_expression_is_refused_at_declaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[every tuesday] is not a cron expression');

        Schedule::cron('every tuesday');
    }

    public function test_a_seconds_field_is_refused_because_a_tick_is_a_minute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a seconds field is refused');

        Schedule::cron('0 0 2 * * *');
    }

    public function test_an_alias_is_a_cron_expression(): void
    {
        $this->assertSame('@daily', Schedule::cron('@daily')->expression);
    }

    public function test_an_unknown_timezone_is_refused_at_declaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[Mars/Olympus] is not a timezone');

        Schedule::cron('@daily')->timezone('Mars/Olympus');
    }

    public function test_a_blank_tenant_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schedule::cron('@daily')->tenant('   ');
    }

    public function test_input_builds_from_the_tick_and_a_lying_callback_is_refused_by_name(): void
    {
        $tick = new DateTimeImmutable('2026-11-03 02:00:00', new DateTimeZone('UTC'));

        $schedule = Schedule::cron('@daily')->input(
            fn (DateTimeImmutable $at): array => ['day' => $at->modify('-1 day')->format('Y-m-d')],
        );

        $this->assertSame(['day' => '2026-11-02'], $schedule->inputFor($tick));

        $static = Schedule::cron('@daily')->input(['fixed' => true]);
        $this->assertSame(['fixed' => true], $static->inputFor($tick));

        $lying = Schedule::cron('@daily')->input(fn (): string => 'not an array');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must return an array');

        $lying->inputFor($tick);
    }

    public function test_the_most_recent_instant_is_deterministic_and_timezone_aware(): void
    {
        $now = new DateTimeImmutable('2026-11-03 14:30:40', new DateTimeZone('UTC'));

        $utc = Ticks::mostRecent('0 2 * * *', 'UTC', $now);
        $this->assertSame('2026-11-03 02:00:00', $utc->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $utc->getTimezone()->getName());

        // 02:00 in New York (EST, UTC-5 in November) is 07:00 UTC.
        $newYork = Ticks::mostRecent('0 2 * * *', 'America/New_York', $now);
        $this->assertSame('2026-11-03 07:00:00', $newYork->format('Y-m-d H:i:s'));

        // Two readers inside the same minute name the same instant —
        // the determinism the unique tick index stands on.
        $later = Ticks::mostRecent('0 2 * * *', 'UTC', $now->modify('+19 seconds'));
        $this->assertEquals($utc, $later);

        // At the instant itself, the instant is due, not the one before.
        $atTwo = Ticks::mostRecent('0 2 * * *', 'UTC', new DateTimeImmutable('2026-11-03 02:00:00', new DateTimeZone('UTC')));
        $this->assertSame('2026-11-03 02:00:00', $atTwo->format('Y-m-d H:i:s'));
    }

    public function test_an_expression_that_never_matches_a_real_date_is_refused_at_declaration(): void
    {
        // February 30th parses; every date computation after it throws.
        // Refused here, where the class is named — not at tick time, in
        // the scheduler, the listing, and the summary at once.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('never matches a real date');

        Schedule::cron('0 0 30 2 *');
    }

    public function test_a_row_whose_expression_no_longer_computes_cannot_crash_the_health_math(): void
    {
        $row = new ScheduledWorkflow(
            id: 1,
            workflow: 'App\\Workflows\\Legacy',
            cron: '0 0 30 2 *',
            timezone: 'UTC',
            missedTickPolicy: MissedTickPolicy::Skip,
            withoutOverlapping: false,
            tenantId: null,
            lastTickAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            pausedAt: null,
            declaredAt: null,
        );

        $now = new DateTimeImmutable('2026-11-03 14:30:00');

        $this->assertFalse(ScheduleStatus::stalled($row, $now));
        $this->assertNull(ScheduleStatus::nextDue($row, $now));
    }

    public function test_a_repeated_dst_hour_fires_once(): void
    {
        // America/New_York, 2026-11-01: 01:30 EDT and 01:30 EST are two
        // UTC instants with one local wall-clock. A daily 01:30 schedule
        // fires on its first occurrence and the second is the same tick.
        $after = new DateTimeImmutable('2026-10-31 05:30:00', new DateTimeZone('UTC'));
        $until = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC'));

        $gap = Ticks::between('30 1 * * *', 'America/New_York', $after, $until);

        $this->assertSame(
            ['2026-11-01 05:30:00', '2026-11-02 06:30:00'],
            array_map(fn (DateTimeImmutable $t): string => $t->format('Y-m-d H:i:s'), $gap),
            'One instant for the repeated hour (01:30 EDT), then the next day under EST.',
        );
    }

    public function test_the_skipped_dst_hour_fires_once_at_the_next_valid_time(): void
    {
        // 2026-03-08: 02:30 does not exist in New York; the library
        // shifts to 03:30 EDT, once.
        $after = new DateTimeImmutable('2026-03-07 07:30:00', new DateTimeZone('UTC'));
        $until = new DateTimeImmutable('2026-03-09 12:00:00', new DateTimeZone('UTC'));

        $gap = Ticks::between('30 2 * * *', 'America/New_York', $after, $until);

        $this->assertCount(2, $gap, 'The spring-forward day fires once, at the shifted hour, and the next day normally.');
        $this->assertSame('2026-03-08 07:30:00', $gap[0]->format('Y-m-d H:i:s'), '03:30 EDT, the next valid local time.');
    }

    public function test_instants_come_back_in_the_callers_timezone(): void
    {
        // The instant is the contract; the representation rides the
        // caller's convention, because Eloquent stores the wall-clock of
        // the value it is given (the Milestone 10 audit's worst finding).
        $now = new DateTimeImmutable('2026-11-03 09:30:00', new DateTimeZone('America/New_York'));

        $tick = Ticks::mostRecent('0 * * * *', 'America/New_York', $now);

        $this->assertSame('America/New_York', $tick->getTimezone()->getName());
        $this->assertSame('2026-11-03 09:00:00', $tick->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 14:00:00', $tick->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function test_between_walks_the_gap_oldest_first_exclusive_inclusive_and_bounded(): void
    {
        $after = new DateTimeImmutable('2026-11-03 02:00:00', new DateTimeZone('UTC'));
        $until = new DateTimeImmutable('2026-11-06 02:00:00', new DateTimeZone('UTC'));

        $gap = Ticks::between('0 2 * * *', 'UTC', $after, $until);

        $this->assertSame(
            ['2026-11-04 02:00:00', '2026-11-05 02:00:00', '2026-11-06 02:00:00'],
            array_map(fn (DateTimeImmutable $t): string => $t->format('Y-m-d H:i:s'), $gap),
            'Strictly after the start, up to and including the end.',
        );

        $capped = Ticks::between('* * * * *', 'UTC', $after, $until, cap: 5);
        $this->assertCount(5, $capped, 'The walk is bounded; a decade-long gap must not cost a decade of arithmetic.');
        $this->assertSame('2026-11-03 02:01:00', $capped[0]->format('Y-m-d H:i:s'));
    }
}
