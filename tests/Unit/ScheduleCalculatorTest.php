<?php

namespace Tests\Unit;

use App\Services\ScheduleCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScheduleCalculatorTest extends TestCase
{
    private ScheduleCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new ScheduleCalculator;
    }

    private function karachi(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Karachi')->utc();
    }

    #[Test]
    public function interval_slots_are_anchored_to_the_start_time(): void
    {
        $anchor = $this->karachi('2026-10-10 09:00');

        // Expected sample from the spec: 09:00, 09:30, 10:00, 10:30, 11:00.
        $runs = [$anchor];
        $next = $anchor;

        for ($i = 0; $i < 4; $i++) {
            $next = $this->calc->nextInterval($anchor, 1800, $next);
            $runs[] = $next;
        }

        $this->assertSame(
            ['09:00', '09:30', '10:00', '10:30', '11:00'],
            array_map(fn ($r) => $r->setTimezone('Asia/Karachi')->format('H:i'), $runs),
        );
    }

    #[Test]
    public function interval_skips_missed_slots_instead_of_bursting(): void
    {
        $anchor = $this->karachi('2026-10-10 09:00');
        // The server was down from 09:10 to 10:47: the next slot is 11:00, not 09:30.
        $next = $this->calc->nextInterval($anchor, 1800, $this->karachi('2026-10-10 10:47'));

        $this->assertSame('11:00', $next->setTimezone('Asia/Karachi')->format('H:i'));
    }

    #[Test]
    public function interval_before_anchor_returns_anchor(): void
    {
        $anchor = $this->karachi('2026-10-10 09:00');

        $this->assertTrue($this->calc->nextInterval($anchor, 600, $this->karachi('2026-10-09 23:00'))->equalTo($anchor));
    }

    #[Test]
    public function custom_interval_of_37_minutes(): void
    {
        $anchor = $this->karachi('2026-10-10 09:00');
        $next = $this->calc->nextInterval($anchor, 37 * 60, $anchor);

        $this->assertSame('09:37', $next->setTimezone('Asia/Karachi')->format('H:i'));
    }

    #[Test]
    public function fixed_times_pick_the_next_slot_of_the_day(): void
    {
        $everyDay = [1, 2, 3, 4, 5, 6, 7];
        $slots = array_map(fn ($t) => ['time' => $t, 'days' => $everyDay], ['09:00', '12:00', '15:00', '18:00', '21:00']);

        $next = $this->calc->nextSlot($slots, 'Asia/Karachi', $this->karachi('2026-10-10 12:00'));
        $this->assertSame('2026-10-10 15:00', $next->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));

        $next = $this->calc->nextSlot($slots, 'Asia/Karachi', $this->karachi('2026-10-10 21:30'));
        $this->assertSame('2026-10-11 09:00', $next->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    #[Test]
    public function daily_slot_rolls_over_to_tomorrow(): void
    {
        $slots = [['time' => '10:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]];

        $next = $this->calc->nextSlot($slots, 'Asia/Karachi', $this->karachi('2026-10-10 10:00'));

        $this->assertSame('2026-10-11 10:00', $next->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    #[Test]
    public function weekly_slots_only_fire_on_selected_weekdays(): void
    {
        // Monday, Wednesday and Friday at 7 PM. 2026-10-10 is a Saturday.
        $slots = [['time' => '19:00', 'days' => [1, 3, 5]]];

        $next = $this->calc->nextSlot($slots, 'Asia/Karachi', $this->karachi('2026-10-10 08:00'));
        $this->assertSame('Mon 2026-10-12 19:00', $next->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'));

        $next = $this->calc->nextSlot($slots, 'Asia/Karachi', $next);
        $this->assertSame('Wed 2026-10-14 19:00', $next->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'));
    }

    #[Test]
    public function slots_respect_the_campaign_timezone_and_return_utc(): void
    {
        $slots = [['time' => '09:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]];

        $karachi = $this->calc->nextSlot($slots, 'Asia/Karachi', CarbonImmutable::parse('2026-10-10 00:00', 'UTC'));
        $london = $this->calc->nextSlot($slots, 'Europe/London', CarbonImmutable::parse('2026-10-10 00:00', 'UTC'));

        $this->assertSame('2026-10-10 04:00', $karachi->format('Y-m-d H:i')); // UTC+5
        $this->assertSame('2026-10-10 08:00', $london->format('Y-m-d H:i'));  // BST, UTC+1
        $this->assertSame('UTC', $karachi->getTimezone()->getName());
    }

    #[Test]
    public function slots_are_not_scheduled_before_the_start_date(): void
    {
        $slots = [['time' => '09:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]];
        $start = $this->karachi('2026-10-20 00:00');

        $next = $this->calc->nextSlot($slots, 'Asia/Karachi', $this->karachi('2026-10-10 08:00'), $start);

        $this->assertSame('2026-10-20 09:00', $next->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    #[Test]
    public function no_slots_means_no_next_run(): void
    {
        $this->assertNull($this->calc->nextSlot([], 'UTC', CarbonImmutable::now()));
        $this->assertNull($this->calc->nextInterval(CarbonImmutable::now(), 0, CarbonImmutable::now()));
    }
}
