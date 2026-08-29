<?php

namespace Tests\Unit;

use App\Services\OpeningHoursService;
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

    public function test_open_now_returns_close_timestamp(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $currentDay = (int) $now->format('w');
        $currentHour = (int) $now->format('H');

        // Open from 2 hours ago until 4 hours from now
        $openHour = str_pad((string) (($currentHour - 2 + 24) % 24), 2, '0', STR_PAD_LEFT);
        $closeHour = str_pad((string) (($currentHour + 4) % 24), 2, '0', STR_PAD_LEFT);

        $periods = json_encode([
            [
                'open' => ['day' => $currentDay, 'time' => $openHour . '00'],
                'close' => ['day' => $currentDay, 'time' => $closeHour . '00'],
            ],
        ]);

        $state = $this->service->getOpenState($periods);

        $this->assertNotNull($state['close_timestamp']);
        $this->assertNotNull($state['open_timestamp']);
        $this->assertTrue($state['is_open']);
    }

    public function test_closed_now_returns_nulls(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $currentDay = (int) $now->format('w');
        $currentHour = (int) $now->format('H');

        // Open from 4 hours from now until 6 hours from now
        $openHour = str_pad((string) (($currentHour + 4) % 24), 2, '0', STR_PAD_LEFT);
        $closeHour = str_pad((string) (($currentHour + 6) % 24), 2, '0', STR_PAD_LEFT);

        $periods = json_encode([
            [
                'open' => ['day' => $currentDay, 'time' => $openHour . '00'],
                'close' => ['day' => $currentDay, 'time' => $closeHour . '00'],
            ],
        ]);

        $state = $this->service->getOpenState($periods);

        $this->assertNull($state['open_timestamp']);
        $this->assertNull($state['close_timestamp']);
        $this->assertFalse($state['is_open']);
    }

    public function test_open_now_with_new_point_format_returns_close_timestamp(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $currentDay = (int) $now->format('w');
        $currentHour = (int) $now->format('H');

        $openHour = ($currentHour - 2 + 24) % 24;
        $closeHour = ($currentHour + 4) % 24;

        $periods = json_encode([
            [
                'open' => ['day' => $currentDay, 'hour' => $openHour, 'minute' => 0],
                'close' => ['day' => $currentDay, 'hour' => $closeHour, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods);

        $this->assertNotNull($state['close_timestamp']);
        $this->assertNotNull($state['open_timestamp']);
        $this->assertTrue($state['is_open']);
    }

    public function test_closed_now_with_new_point_format_returns_nulls(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $currentDay = (int) $now->format('w');
        $currentHour = (int) $now->format('H');

        $openHour = ($currentHour + 4) % 24;
        $closeHour = ($currentHour + 6) % 24;

        $periods = json_encode([
            [
                'open' => ['day' => $currentDay, 'hour' => $openHour, 'minute' => 0],
                'close' => ['day' => $currentDay, 'hour' => $closeHour, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods);

        $this->assertNull($state['open_timestamp']);
        $this->assertNull($state['close_timestamp']);
        $this->assertFalse($state['is_open']);
    }

    public function test_overnight_period_with_new_point_format(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $currentDay = (int) $now->format('w');
        $yesterday = ($currentDay - 1 + 7) % 7;

        $periods = json_encode([
            [
                'open' => ['day' => $yesterday, 'hour' => 22, 'minute' => 0],
                'close' => ['day' => $currentDay, 'hour' => 4, 'minute' => 0],
            ],
        ]);

        $state = $this->service->getOpenState($periods);
        $this->assertIsArray($state);
    }
}
