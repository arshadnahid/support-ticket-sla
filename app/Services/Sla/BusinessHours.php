<?php

namespace App\Services\Sla;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendar maths for SLA deadlines: "what time is it after N working minutes?" and
 * "how many working minutes are left until then?".
 *
 * Working time is the configured days and hours (config/sla.php), minus holidays, read in the
 * configured timezone. Everything is stored and returned in UTC; the timezone only decides
 * where the working day starts and ends.
 *
 * Example (Sunday to Thursday, 09:00 to 18:00): a 2-hour SLA starting Thursday 17:00 has only
 * 1 working hour left that day, so the second hour is spent on Sunday 09:00 to 10:00 and the
 * deadline is Sunday 10:00. Friday and Saturday do not count.
 *
 * Not covered on purpose: several shifts per day, per-customer calendars, lunch breaks,
 * different hours per weekday.
 */
class BusinessHours
{
    /** Days to look ahead for the next opening before concluding the calendar has none. */
    private const MAX_LOOKAHEAD_DAYS = 400;

    /**
     * @param  list<int>  $days  0 = Sunday ... 6 = Saturday
     * @param  list<string>  $holidays  'YYYY-MM-DD'
     */
    public function __construct(
        private readonly string $timezone,
        private readonly array $days,
        private readonly string $start,
        private readonly string $end,
        private readonly array $holidays = [],
    ) {
        if ($days === []) {
            throw new InvalidArgumentException('Business hours need at least one working day.');
        }

        if ($this->minutesOf($start) >= $this->minutesOf($end)) {
            throw new InvalidArgumentException('Business hours must open before they close.');
        }
    }

    public static function fromConfig(): self
    {
        $c = config('sla.business_hours');

        return new self($c['timezone'], $c['days'], $c['start'], $c['end'], $c['holidays']);
    }

    /**
     * The instant that is $minutes of working time after $from.
     */
    public function addMinutes(CarbonInterface $from, float|int $minutes): CarbonImmutable
    {
        $remaining = (int) round($minutes * 60); // work in seconds so fractions of a minute are kept
        $at = $this->nextOpening($from);

        while (true) {
            $close = $this->closeOf($at);
            $left = $close->getTimestamp() - $at->getTimestamp();

            if ($remaining <= $left) {
                return $at->addSeconds($remaining)->utc();
            }

            $remaining -= $left;
            $at = $this->nextOpening($close);
        }
    }

    /**
     * Working minutes between two instants (0 when $to is not after $from).
     */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0.0;
        }

        $seconds = 0;
        $at = $this->nextOpening($from);

        while ($at->lessThan($to)) {
            $close = $this->closeOf($at);
            $seconds += min($close->getTimestamp(), $to->getTimestamp()) - $at->getTimestamp();
            $at = $this->nextOpening($close);
        }

        return $seconds / 60;
    }

    /**
     * $at itself when the office is open, otherwise the next moment it opens.
     */
    public function nextOpening(CarbonInterface $at): CarbonImmutable
    {
        $t = CarbonImmutable::instance($at)->setTimezone($this->timezone);

        for ($i = 0; $i <= self::MAX_LOOKAHEAD_DAYS; $i++) {
            if ($this->isWorkingDay($t)) {
                $open = $this->at($t, $this->start);
                $close = $this->at($t, $this->end);

                if ($t->lessThan($open)) {
                    return $open;
                }

                if ($t->lessThan($close)) {
                    return $t;
                }
            }

            $t = $t->addDay()->startOfDay();
        }

        throw new InvalidArgumentException('No working time found in the configured business hours.');
    }

    public function isOpenAt(CarbonInterface $at): bool
    {
        return $this->nextOpening($at)->equalTo($at);
    }

    private function isWorkingDay(CarbonImmutable $day): bool
    {
        return in_array($day->dayOfWeek, $this->days, true)
            && ! in_array($day->format('Y-m-d'), $this->holidays, true);
    }

    private function closeOf(CarbonImmutable $day): CarbonImmutable
    {
        return $this->at($day, $this->end);
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->setTime($hour, $minute);
    }

    private function minutesOf(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
