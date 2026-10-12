<?php

namespace Tests\Unit;

use App\Services\Sla\BusinessHours;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Calendar for these tests: Sunday to Thursday, 09:00 to 18:00.
 * Dates used: Sun 2026-10-11, Mon 12, Tue 13, Wed 14, Thu 15, Fri 16, Sat 17, Sun 18.
 */
class BusinessHoursTest extends TestCase
{
    private function hours(string $tz = 'UTC', array $holidays = []): BusinessHours
    {
        return new BusinessHours($tz, [0, 1, 2, 3, 4], '09:00', '18:00', $holidays);
    }

    private function at(string $time, string $tz = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $tz);
    }

    public function test_the_assessment_example_critical_ticket_on_thursday_5pm(): void
    {
        // 1 working hour left on Thursday; the second hour is Sunday 09:00 to 10:00.
        $due = $this->hours()->addMinutes($this->at('2026-10-15 17:00'), 120);

        $this->assertSame('2026-10-18 10:00', $due->format('Y-m-d H:i'));
    }

    public function test_inside_one_working_day_it_is_plain_addition(): void
    {
        $due = $this->hours()->addMinutes($this->at('2026-10-12 10:00'), 120);

        $this->assertSame('2026-10-12 12:00', $due->format('Y-m-d H:i'));
    }

    public function test_finishing_exactly_at_closing_time_stays_on_the_same_day(): void
    {
        $due = $this->hours()->addMinutes($this->at('2026-10-12 16:00'), 120);

        $this->assertSame('2026-10-12 18:00', $due->format('Y-m-d H:i'));
    }

    public function test_created_before_opening_starts_counting_at_opening(): void
    {
        $due = $this->hours()->addMinutes($this->at('2026-10-12 06:00'), 120);

        $this->assertSame('2026-10-12 11:00', $due->format('Y-m-d H:i'));
    }

    public function test_created_after_closing_starts_counting_next_working_morning(): void
    {
        $due = $this->hours()->addMinutes($this->at('2026-10-12 20:30'), 120);

        $this->assertSame('2026-10-13 11:00', $due->format('Y-m-d H:i'));
    }

    public function test_created_on_the_weekend_starts_counting_on_sunday(): void
    {
        $friday = $this->hours()->addMinutes($this->at('2026-10-16 10:00'), 120);
        $saturday = $this->hours()->addMinutes($this->at('2026-10-17 23:00'), 120);

        $this->assertSame('2026-10-18 11:00', $friday->format('Y-m-d H:i'));
        $this->assertSame('2026-10-18 11:00', $saturday->format('Y-m-d H:i'));
    }

    public function test_a_long_sla_spans_several_days_and_skips_the_weekend(): void
    {
        // 48 working hours = 5 full days (Sun to Thu = 45 h) + 3 h on the next Sunday.
        $due = $this->hours()->addMinutes($this->at('2026-10-11 09:00'), 48 * 60);

        $this->assertSame('2026-10-18 12:00', $due->format('Y-m-d H:i'));
    }

    public function test_holidays_do_not_count(): void
    {
        // Sunday 18th is a holiday, so the Thursday overflow lands on Monday 19th.
        $due = $this->hours(holidays: ['2026-10-18'])->addMinutes($this->at('2026-10-15 17:00'), 120);

        $this->assertSame('2026-10-19 10:00', $due->format('Y-m-d H:i'));
    }

    public function test_fractions_of_a_minute_are_kept(): void
    {
        $due = $this->hours()->addMinutes($this->at('2026-10-12 10:00'), 0.5);

        $this->assertSame('2026-10-12 10:00:30', $due->format('Y-m-d H:i:s'));
    }

    public function test_the_working_day_is_read_in_the_configured_timezone_and_the_result_is_utc(): void
    {
        // 11:00 UTC is 17:00 in Dhaka (UTC+6) on Thursday. Deadline: Sunday 10:00 Dhaka = 04:00 UTC.
        $due = $this->hours('Asia/Dhaka')->addMinutes($this->at('2026-10-15 11:00'), 120);

        $this->assertSame('UTC', $due->timezoneName);
        $this->assertSame('2026-10-18 04:00', $due->format('Y-m-d H:i'));
    }

    public function test_working_minutes_between_two_instants(): void
    {
        $hours = $this->hours();

        $this->assertSame(120.0, $hours->minutesBetween($this->at('2026-10-15 17:00'), $this->at('2026-10-18 10:00')));
        $this->assertSame(90.0, $hours->minutesBetween($this->at('2026-10-12 10:00'), $this->at('2026-10-12 11:30')));
        $this->assertSame(0.0, $hours->minutesBetween($this->at('2026-10-16 10:00'), $this->at('2026-10-17 10:00')), 'a weekend has no working time');
        $this->assertSame(0.0, $hours->minutesBetween($this->at('2026-10-12 12:00'), $this->at('2026-10-12 10:00')), 'never negative');
    }

    public function test_minutes_between_is_the_inverse_of_add_minutes(): void
    {
        $hours = $this->hours();
        $start = $this->at('2026-10-15 16:20');

        foreach ([30, 120, 600, 24 * 60, 48 * 60] as $minutes) {
            $this->assertSame((float) $minutes, $hours->minutesBetween($start, $hours->addMinutes($start, $minutes)));
        }
    }

    public function test_is_open_at(): void
    {
        $hours = $this->hours();

        $this->assertTrue($hours->isOpenAt($this->at('2026-10-12 09:00')));
        $this->assertTrue($hours->isOpenAt($this->at('2026-10-12 17:59')));
        $this->assertFalse($hours->isOpenAt($this->at('2026-10-12 18:00')));
        $this->assertFalse($hours->isOpenAt($this->at('2026-10-12 08:59')));
        $this->assertFalse($hours->isOpenAt($this->at('2026-10-16 12:00')), 'Friday');
    }

    public function test_a_calendar_without_working_time_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BusinessHours('UTC', [], '09:00', '18:00');
    }

    public function test_closing_before_opening_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BusinessHours('UTC', [0], '18:00', '09:00');
    }
}
