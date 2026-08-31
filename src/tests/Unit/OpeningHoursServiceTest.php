<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OpeningHoursService;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class OpeningHoursServiceTest extends TestCase
{
    private OpeningHoursService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpeningHoursService('Europe/Berlin');
    }

    public function test_empty_json_returns_nulls(): void
    {
        $state = $this->service->getOpenState(null);

        $this->assertNull($state['open_timestamp']);
        $this->assertNull($state['close_timestamp']);
        $this->assertFalse($state['is_open']);
    }

    public function test_invalid_json_returns_nulls(): void
    {
        $state = $this->service->getOpenState('not valid json');

        $this->assertNull($state['open_timestamp']);
        $this->assertNull($state['close_timestamp']);
        $this->assertFalse($state['is_open']);
    }

    public function test_open_now_returns_current_open_and_next_close_timestamp(): void
    {
        // Reference time: Monday 2026-08-31 12:00:00 (day = 1)
        $refTime = new DateTime('2026-08-31 12:00:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 1, 'hour' => 9, 'minute' => 0],
                'close' => ['day' => 1, 'hour' => 18, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertTrue($state['is_open']);
        $this->assertSame('2026-08-31T09:00:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-08-31T18:00:00+02:00', $state['close_timestamp']);
    }

    public function test_closed_now_returns_next_open_and_close_timestamps_later_today(): void
    {
        // Reference time: Monday 2026-08-31 07:00:00 (day = 1)
        $refTime = new DateTime('2026-08-31 07:00:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 1, 'hour' => 9, 'minute' => 0],
                'close' => ['day' => 1, 'hour' => 18, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertFalse($state['is_open']);
        $this->assertSame('2026-08-31T09:00:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-08-31T18:00:00+02:00', $state['close_timestamp']);
    }

    public function test_closed_now_returns_next_open_and_close_timestamps_tomorrow(): void
    {
        // Reference time: Monday 2026-08-31 20:00:00 (day = 1)
        $refTime = new DateTime('2026-08-31 20:00:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 1, 'hour' => 9, 'minute' => 0],
                'close' => ['day' => 1, 'hour' => 18, 'minute' => 0],
            ],
            [
                'open' => ['day' => 2, 'hour' => 8, 'minute' => 30],
                'close' => ['day' => 2, 'hour' => 17, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertFalse($state['is_open']);
        $this->assertSame('2026-09-01T08:30:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-09-01T17:00:00+02:00', $state['close_timestamp']);
    }

    public function test_closed_over_weekend_returns_next_monday_timestamps(): void
    {
        // Reference time: Friday 2026-08-28 20:00:00 (day = 5)
        $refTime = new DateTime('2026-08-28 20:00:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 1, 'hour' => 9, 'minute' => 0],
                'close' => ['day' => 1, 'hour' => 18, 'minute' => 0],
            ],
            [
                'open' => ['day' => 5, 'hour' => 9, 'minute' => 0],
                'close' => ['day' => 5, 'hour' => 18, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertFalse($state['is_open']);
        $this->assertSame('2026-08-31T09:00:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-08-31T18:00:00+02:00', $state['close_timestamp']);
    }

    public function test_split_hours_during_break_returns_afternoon_session(): void
    {
        // Reference time: Monday 2026-08-31 13:00:00
        $refTime = new DateTime('2026-08-31 13:00:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 1, 'hour' => 9, 'minute' => 0],
                'close' => ['day' => 1, 'hour' => 12, 'minute' => 0],
            ],
            [
                'open' => ['day' => 1, 'hour' => 14, 'minute' => 0],
                'close' => ['day' => 1, 'hour' => 18, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertFalse($state['is_open']);
        $this->assertSame('2026-08-31T14:00:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-08-31T18:00:00+02:00', $state['close_timestamp']);
    }

    public function test_overnight_period_when_currently_open(): void
    {
        // Opens Friday 22:00, closes Saturday 04:00.
        // Reference time: Saturday 2026-08-29 02:00:00 (day = 6)
        $refTime = new DateTime('2026-08-29 02:00:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 5, 'hour' => 22, 'minute' => 0],
                'close' => ['day' => 6, 'hour' => 4, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertTrue($state['is_open']);
        $this->assertSame('2026-08-28T22:00:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-08-29T04:00:00+02:00', $state['close_timestamp']);
    }

    public function test_overnight_period_spanning_saturday_to_sunday(): void
    {
        // Opens Saturday 21:00, closes Sunday 05:00.
        // Reference time: Sunday 2026-08-30 01:30:00 (day = 0)
        $refTime = new DateTime('2026-08-30 01:30:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 6, 'hour' => 21, 'minute' => 0],
                'close' => ['day' => 0, 'hour' => 5, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertTrue($state['is_open']);
        $this->assertSame('2026-08-29T21:00:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-08-30T05:00:00+02:00', $state['close_timestamp']);
    }

    public function test_legacy_point_time_format(): void
    {
        // Reference time: Monday 2026-08-31 10:00:00
        $refTime = new DateTime('2026-08-31 10:00:00', new DateTimeZone('Europe/Berlin'));

        $periods = json_encode([
            [
                'open' => ['day' => 1, 'time' => '0900'],
                'close' => ['day' => 1, 'time' => '1800'],
            ],
        ]);

        $state = $this->service->getOpenState($periods, $refTime);

        $this->assertTrue($state['is_open']);
        $this->assertSame('2026-08-31T09:00:00+02:00', $state['open_timestamp']);
        $this->assertSame('2026-08-31T18:00:00+02:00', $state['close_timestamp']);
    }

    public function test_24_7_opening_hours_returns_is_open_true_with_null_timestamps(): void
    {
        $periods = json_encode([
            [
                'open' => ['day' => 0, 'hour' => 0, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods);

        $this->assertTrue($state['is_open']);
        $this->assertNull($state['open_timestamp']);
        $this->assertNull($state['close_timestamp']);
    }
}
