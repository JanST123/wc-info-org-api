<?php

namespace App\Services;

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
     * @param string|null $periodsJson JSON encoded Google Places opening_hours.periods array
     * @return array{open_timestamp: string|null, close_timestamp: string|null, is_open: bool}
     */
    public function getOpenState(?string $periodsJson): array
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

            $now = new \DateTime('now', new \DateTimeZone($this->timezone));
            $currentDay = (int) $now->format('w'); // 0 = Sunday ... 6 = Saturday
            $currentMinutes = (int) $now->format('H') * 60 + (int) $now->format('i');

            foreach ($periods as $period) {
                if (! isset($period['open']) || ! is_array($period['open']) || ! isset($period['open']['day'])) {
                    continue;
                }

                $openDay = (int) $period['open']['day'];
                $openMinutes = $this->extractMinutes($period['open']);

                if ($openMinutes === null) {
                    continue;
                }

                $closeDay = isset($period['close']['day']) ? (int) $period['close']['day'] : $openDay;
                $closeMinutes = isset($period['close']) && is_array($period['close'])
                    ? $this->extractMinutes($period['close'])
                    : null;

                // Normalize days to the same weekly cycle relative to today.
                $openCandidate = $this->normalizeDay($openDay, $currentDay);
                $closeCandidate = $this->normalizeDay($closeDay, $currentDay);

                // If close is before open, the period spans to the next day.
                if ($closeCandidate < $openCandidate) {
                    $closeCandidate += 7;
                }

                // Convert to absolute minute-of-week for comparison.
                $openAbsolute = $openCandidate * 24 * 60 + $openMinutes;
                $closeAbsolute = $closeMinutes !== null
                    ? $closeCandidate * 24 * 60 + $closeMinutes
                    : $openAbsolute + 24 * 60; // open 24h if no close

                $currentAbsolute = $currentDay * 24 * 60 + $currentMinutes;

                if ($currentAbsolute >= $openAbsolute && $currentAbsolute < $closeAbsolute) {
                    $result['open_timestamp'] = $this->formatTimestamp($openAbsolute);
                    $result['close_timestamp'] = $this->formatTimestamp($closeAbsolute);
                    $result['is_open'] = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Cannot parse periods; return default nulls.
        }

        return $result;
    }

    private function extractMinutes(array $point): ?int
    {
        if (isset($point['hour'])) {
            $hour = (int) $point['hour'];
            $minute = (int) ($point['minute'] ?? 0);
            return $hour * 60 + $minute;
        }

        if (isset($point['hours'])) {
            $hour = (int) $point['hours'];
            $minute = (int) ($point['minutes'] ?? 0);
            return $hour * 60 + $minute;
        }

        if (isset($point['time']) && is_string($point['time']) && strlen($point['time']) >= 4) {
            return $this->parseTime($point['time']);
        }

        return null;
    }

    private function parseTime(string $time): int
    {
        $hour = (int) substr($time, 0, 2);
        $minute = (int) substr($time, 2, 2);

        return $hour * 60 + $minute;
    }

    /**
     * Normalize a Google Places day number (0=Sun) to a day number relative to the current day.
     */
    private function normalizeDay(int $day, int $currentDay): int
    {
        // Google uses Sunday=0; PHP uses Sunday=0, so no conversion needed.
        $diff = $day - $currentDay;

        if ($diff < -3) {
            $diff += 7;
        } elseif ($diff > 3) {
            $diff -= 7;
        }

        return $currentDay + $diff;
    }

    private function formatTimestamp(int $absoluteMinutes): string
    {
        $dayOffset = intdiv($absoluteMinutes, 24 * 60);
        $minutesOfDay = $absoluteMinutes % (24 * 60);
        $hour = intdiv($minutesOfDay, 60);
        $minute = $minutesOfDay % 60;

        $date = new \DateTime('now', new \DateTimeZone($this->timezone));
        $date->setTime($hour, $minute, 0);

        if ($dayOffset !== 0) {
            $date->modify(($dayOffset > 0 ? '+' : '') . $dayOffset . ' days');
        }

        return $date->format('c');
    }
}
