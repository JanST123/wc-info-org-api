<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleNearbySearchCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GoogleNearbyCacheService
{
    /**
     * Approximate Earth radius in meters.
     */
    private const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Find a fresh cached nearby search whose coverage circle fully encloses
     * the requested center coordinate and search radius.
     *
     * Geometric enclosure condition:
     * distance(center_requested, center_cached) + radius_requested <= radius_cached
     */
    public function findEnclosingCache(
        float $lat,
        float $lon,
        float $radiusMeters,
        int $freshnessDays = 30
    ): ?GoogleNearbySearchCache {
        $since = Carbon::now()->subDays($freshnessDays);

        // Latitude & longitude bounding box pre-filter for performance (max 50km radius)
        $maxDeltaDegLat = 50000.0 / 111000.0;
        $cosLat = max(0.01, cos(deg2rad($lat)));
        $maxDeltaDegLon = 50000.0 / (111000.0 * $cosLat);

        $candidates = GoogleNearbySearchCache::where('created_at', '>=', $since)
            ->where('radius_meters', '>=', $radiusMeters)
            ->whereBetween('lat', [$lat - $maxDeltaDegLat, $lat + $maxDeltaDegLat])
            ->whereBetween('lon', [$lon - $maxDeltaDegLon, $lon + $maxDeltaDegLon])
            ->orderByDesc('created_at')
            ->get();

        foreach ($candidates as $candidate) {
            $effectiveRadius = $this->getEffectiveRadiusMeters($candidate);

            // If effective covered radius is smaller than requested radius, it cannot enclose the query
            if ($effectiveRadius < $radiusMeters) {
                continue;
            }

            $centerDistance = $this->haversineDistanceMeters($lat, $lon, (float) $candidate->lat, (float) $candidate->lon);

            // Check if requested circle falls completely inside the effective cached circle
            // Allow 1.0 meter floating-point tolerance
            if (($centerDistance + $radiusMeters) <= ($effectiveRadius + 1.0)) {
                Log::debug('Google Nearby Search cache HIT (spatial enclosure)', [
                    'requested_lat' => $lat,
                    'requested_lon' => $lon,
                    'requested_radius_m' => $radiusMeters,
                    'cached_id' => $candidate->id,
                    'cached_lat' => $candidate->lat,
                    'cached_lon' => $candidate->lon,
                    'cached_radius_m' => $candidate->radius_meters,
                    'effective_radius_m' => round($effectiveRadius, 2),
                    'center_distance_m' => round($centerDistance, 2),
                ]);

                return $candidate;
            }
        }

        return null;
    }

    /**
     * Compute the effective radius in meters that is guaranteed to be completely covered by the cached results.
     *
     * If the API returned fewer results than maxResultCount (or 0 results), the entire requested radius
     * was exhaustively searched without truncation.
     * If the API returned results up to maxResultCount, Google stopped scanning and truncated results
     * at the distance of the farthest place returned.
     */
    public function getEffectiveRadiusMeters(GoogleNearbySearchCache $cache): float
    {
        $places = $cache->response_places ?? [];
        $count = count($places);
        $maxLimit = (int) ($cache->query_params['maxResultCount'] ?? 10);

        // If fewer results than the API limit, the search was exhaustively complete
        if ($count < $maxLimit) {
            return (float) $cache->radius_meters;
        }

        // If limit was reached, find distance of farthest place from cache center
        $maxDist = 0.0;
        foreach ($places as $place) {
            $coords = $this->extractPlaceCoordinates($place);
            if ($coords !== null) {
                $dist = $this->haversineDistanceMeters((float) $cache->lat, (float) $cache->lon, $coords['lat'], $coords['lon']);
                if ($dist > $maxDist) {
                    $maxDist = $dist;
                }
            }
        }

        return $maxDist > 0.0 ? $maxDist : (float) $cache->radius_meters;
    }

    /**
     * Filter and rank places from a cached response to only those within the requested sub-radius.
     *
     * @param array<int, array> $places
     * @return array<int, array>
     */
    public function filterCachedPlaces(
        array $places,
        float $lat,
        float $lon,
        float $radiusMeters,
        ?int $limit = null
    ): array {
        $placesWithDistance = [];

        foreach ($places as $place) {
            $coords = $this->extractPlaceCoordinates($place);
            if ($coords === null) {
                // If coordinates are missing, include the place to be safe
                $placesWithDistance[] = [
                    'distance' => 0.0,
                    'place' => $place,
                ];
                continue;
            }

            $dist = $this->haversineDistanceMeters($lat, $lon, $coords['lat'], $coords['lon']);

            // Keep only places within the requested radius (with 1.0m tolerance)
            if ($dist <= ($radiusMeters + 1.0)) {
                $placesWithDistance[] = [
                    'distance' => $dist,
                    'place' => $place,
                ];
            }
        }

        // Sort ascending by distance from the requested center point (matching Google rankPreference: DISTANCE)
        usort($placesWithDistance, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        $filtered = array_map(fn ($item) => $item['place'], $placesWithDistance);

        if ($limit !== null && $limit > 0) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        return $filtered;
    }

    /**
     * Store a Google Places Nearby Search response in the cache table.
     *
     * @param array<int, array> $places
     * @param array<string, mixed> $queryParams
     */
    public function store(
        float $lat,
        float $lon,
        float $radiusMeters,
        array $places,
        array $queryParams = []
    ): GoogleNearbySearchCache {
        return GoogleNearbySearchCache::create([
            'lat' => $lat,
            'lon' => $lon,
            'radius_meters' => $radiusMeters,
            'query_params' => $queryParams,
            'response_places' => $places,
            'result_count' => count($places),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Delete expired cache records older than the specified number of days.
     */
    public function cleanupStale(int $olderThanDays = 90): int
    {
        return GoogleNearbySearchCache::where('created_at', '<', Carbon::now()->subDays($olderThanDays))->delete();
    }

    /**
     * Compute Haversine distance in meters between two lat/lon coordinates.
     */
    public function haversineDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2.0) * sin($dLat / 2.0) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2.0) * sin($dLon / 2.0);

        $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Extract lat/lon float coordinates from diverse Google Place data representations.
     *
     * @param array<string, mixed> $place
     * @return array{lat: float, lon: float}|null
     */
    private function extractPlaceCoordinates(array $place): ?array
    {
        // Google Places API (New) format: places.location.latitude / places.location.longitude
        if (isset($place['location']['latitude'], $place['location']['longitude'])) {
            return [
                'lat' => (float) $place['location']['latitude'],
                'lon' => (float) $place['location']['longitude'],
            ];
        }

        // Legacy format: place.geometry.location.lat / place.geometry.location.lng
        if (isset($place['geometry']['location']['lat'], $place['geometry']['location']['lng'])) {
            return [
                'lat' => (float) $place['geometry']['location']['lat'],
                'lon' => (float) $place['geometry']['location']['lng'],
            ];
        }

        // Direct lat / lon keys
        if (isset($place['lat'], $place['lon'])) {
            return [
                'lat' => (float) $place['lat'],
                'lon' => (float) $place['lon'],
            ];
        }

        return null;
    }
}
