<?php

declare(strict_types=1);

namespace App\Services;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class OpeningHoursService
{
    private string $timezone;

    public function __construct(?string $timezone = null)
    {
        $this->timezone = $timezone ?? (string) config('app.timezone', 'Europe/Berlin');
    }

    /**
     * Determine the current open/close timestamps from Google Places periods data.
     *
     * If the location is currently open, `open_timestamp` is when the current open
     * period began and `close_timestamp` is when it will close next.
     * If the location is currently closed, `open_timestamp` is when it will next open
     * from now and `close_timestamp` is when that upcoming period ends.
     *
     * @param  string|null  $periodsJson  JSON encoded Google Places opening_hours.periods array
     * @param  DateTimeInterface|null  $now  Optional reference time (defaults to current time)
     * @return array{open_timestamp: string|null, close_timestamp: string|null, is_open: bool}
     */
    public function getOpenState(?string $periodsJson, ?DateTimeInterface $now = null): array
    {
        $result = [
            'open_timestamp' => null,
            'close_timestamp' => null,
            'is_open' => false,
        ];

        if (empty($periodsJson)) {
            return $result;
        }

        try {
            $periods = json_decode($periodsJson, true);

            if (! is_array($periods) || count($periods) === 0) {
                return $result;
            }

            $refTime = $this->normalizeDateTime($now);

            // Handle 24/7 opening hours (single period with open day 0 and no close, or no close at all)
            if (count($periods) === 1 && ! isset($periods[0]['close'])) {
                return [
                    'open_timestamp' => null,
                    'close_timestamp' => null,
                    'is_open' => true,
                ];
            }

            $intervals = $this->buildIntervals($periods, $refTime);

            if (empty($intervals)) {
                return $result;
            }

            $merged = $this->mergeIntervals($intervals);

            // 1. Check if currently open
            foreach ($merged as $interval) {
                if ($refTime >= $interval['open'] && $refTime < $interval['close']) {
                    // Check if open 24/7 (continuous throughout window)
                    $windowStart = (clone $refTime)->modify('-6 days');
                    $windowEnd = (clone $refTime)->modify('+6 days');
                    if ($interval['open'] <= $windowStart && $interval['close'] >= $windowEnd) {
                        return [
                            'open_timestamp' => null,
                            'close_timestamp' => null,
                            'is_open' => true,
                        ];
                    }

                    return [
                        'open_timestamp' => $interval['open']->format('c'),
                        'close_timestamp' => $interval['close']->format('c'),
                        'is_open' => true,
                    ];
                }
            }

            // 2. Currently closed: find next upcoming open interval
            foreach ($merged as $interval) {
                if ($interval['open'] > $refTime) {
                    return [
                        'open_timestamp' => $interval['open']->format('c'),
                        'close_timestamp' => $interval['close']->format('c'),
                        'is_open' => false,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Cannot parse periods; return default nulls.
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $periods
     * @return array<int, array{open: DateTime, close: DateTime}>
     */
    private function buildIntervals(array $periods, DateTime $refTime): array
    {
        $todayMidnight = (clone $refTime)->setTime(0, 0, 0);
        $intervals = [];

        for ($offset = -7; $offset <= 7; $offset++) {
            $dayBase = (clone $todayMidnight)->modify(($offset >= 0 ? "+{$offset}" : "{$offset}").' days');
            $dayOfWeek = (int) $dayBase->format('w'); // 0=Sun ... 6=Sat

            foreach ($periods as $period) {
                if (! is_array($period) || ! isset($period['open']) || ! is_array($period['open']) || ! isset($period['open']['day'])) {
                    continue;
                }

                $openDay = (int) $period['open']['day'];
                if ($openDay !== $dayOfWeek) {
                    continue;
                }

                $openTime = $this->extractTime($period['open']);
                if ($openTime === null) {
                    continue;
                }

                $openDateTime = (clone $dayBase)->setTime($openTime['hour'], $openTime['minute'], 0);

                if (! isset($period['close']) || ! is_array($period['close']) || ! isset($period['close']['day'])) {
                    $closeDateTime = (clone $openDateTime)->modify('+24 hours');
                } else {
                    $closeDay = (int) $period['close']['day'];
                    $closeTime = $this->extractTime($period['close']);

                    if ($closeTime === null) {
                        $closeDateTime = (clone $openDateTime)->modify('+24 hours');
                    } else {
                        $dayDiff = ($closeDay - $openDay + 7) % 7;
                        if ($dayDiff === 0 && ($closeTime['hour'] * 60 + $closeTime['minute'] <= $openTime['hour'] * 60 + $openTime['minute'])) {
                            $dayDiff = 1;
                        }

                        $closeDateTime = (clone $dayBase)->modify("+{$dayDiff} days")->setTime($closeTime['hour'], $closeTime['minute'], 0);
                    }
                }

                $intervals[] = [
                    'open' => $openDateTime,
                    'close' => $closeDateTime,
                ];
            }
        }

        usort($intervals, fn (array $a, array $b) => $a['open'] <=> $b['open']);

        return $intervals;
    }

    /**
     * @param  array<int, array{open: DateTime, close: DateTime}>  $intervals
     * @return array<int, array{open: DateTime, close: DateTime}>
     */
    private function mergeIntervals(array $intervals): array
    {
        $merged = [];

        foreach ($intervals as $interval) {
            if (empty($merged)) {
                $merged[] = $interval;

                continue;
            }

            $lastIdx = count($merged) - 1;
            if ($interval['open'] <= $merged[$lastIdx]['close']) {
                if ($interval['close'] > $merged[$lastIdx]['close']) {
                    $merged[$lastIdx]['close'] = $interval['close'];
                }
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    private function normalizeDateTime(?DateTimeInterface $dateTime): DateTime
    {
        $tz = new DateTimeZone($this->timezone);

        if ($dateTime instanceof DateTime) {
            $dt = clone $dateTime;
            $dt->setTimezone($tz);

            return $dt;
        }

        if ($dateTime instanceof DateTimeImmutable) {
            $dt = DateTime::createFromImmutable($dateTime);
            $dt->setTimezone($tz);

            return $dt;
        }

        return new DateTime('now', $tz);
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array{hour: int, minute: int}|null
     */
    private function extractTime(array $point): ?array
    {
        if (isset($point['hour'])) {
            return [
                'hour' => (int) $point['hour'],
                'minute' => (int) ($point['minute'] ?? 0),
            ];
        }

        if (isset($point['hours'])) {
            return [
                'hour' => (int) $point['hours'],
                'minute' => (int) ($point['minutes'] ?? 0),
            ];
        }

        if (isset($point['time']) && is_string($point['time']) && strlen($point['time']) >= 4) {
            return [
                'hour' => (int) substr($point['time'], 0, 2),
                'minute' => (int) substr($point['time'], 2, 2),
            ];
        }

        return null;
    }
}
