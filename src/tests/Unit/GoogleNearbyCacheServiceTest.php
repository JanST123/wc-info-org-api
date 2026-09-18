<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GoogleNearbySearchCache;
use App\Services\GoogleNearbyCacheService;
use Carbon\Carbon;
use Tests\TestCase;

class GoogleNearbyCacheServiceTest extends TestCase
{
    private GoogleNearbyCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        GoogleNearbySearchCache::truncate();
        $this->service = new GoogleNearbyCacheService();
    }

    public function test_haversine_distance_calculation(): void
    {
        // Berlin Alexanderplatz (52.521918, 13.413215) to Brandenburg Gate (52.516275, 13.377704) ~ 2.49 km
        $dist = $this->service->haversineDistanceMeters(52.521918, 13.413215, 52.516275, 13.377704);
        $this->assertGreaterThan(2400, $dist);
        $this->assertLessThan(2600, $dist);

        // Same point distance is 0
        $this->assertEquals(0.0, $this->service->haversineDistanceMeters(52.52, 13.40, 52.52, 13.40));
    }

    public function test_find_enclosing_cache_hits_when_requested_circle_is_inside_cached_circle(): void
    {
        // Cached circle at Alexanderplatz with radius 2000m
        $cached = $this->service->store(52.521918, 13.413215, 2000.0, [
            [
                'id' => 'place-1',
                'displayName' => ['text' => 'Alexanderplatz WC'],
                'location' => ['latitude' => 52.5219, 'longitude' => 13.4132],
            ],
            [
                'id' => 'place-2',
                'displayName' => ['text' => 'Hackescher Markt WC'],
                'location' => ['latitude' => 52.5225, 'longitude' => 13.4020],
            ],
        ]);

        // 1. Exact center query with smaller radius 500m -> Enclosed!
        $hit = $this->service->findEnclosingCache(52.521918, 13.413215, 500.0);
        $this->assertNotNull($hit);
        $this->assertEquals($cached->id, $hit->id);

        // 2. Query 300m away with radius 1000m -> Distance (300m) + Radius (1000m) = 1300m <= 2000m -> Enclosed!
        // ~300m west of Alexanderplatz
        $hit2 = $this->service->findEnclosingCache(52.5219, 13.4088, 1000.0);
        $this->assertNotNull($hit2);
        $this->assertEquals($cached->id, $hit2->id);

        // 3. Query with larger radius than cached (e.g. 2500m) -> Not enclosed!
        $missLargeRadius = $this->service->findEnclosingCache(52.521918, 13.413215, 2500.0);
        $this->assertNull($missLargeRadius);

        // 4. Query 1800m away with radius 500m -> Distance (1800m) + Radius (500m) = 2300m > 2000m -> Not enclosed!
        $missFar = $this->service->findEnclosingCache(52.516275, 13.377704, 500.0);
        $this->assertNull($missFar);
    }

    public function test_find_enclosing_cache_ignores_stale_cache_entries(): void
    {
        $cached = $this->service->store(52.52, 13.40, 2000.0, []);
        $cached->created_at = Carbon::now()->subDays(35); // 35 days old (> 30 days)
        $cached->save();

        $hit = $this->service->findEnclosingCache(52.52, 13.40, 500.0, 30);
        $this->assertNull($hit);
    }

    public function test_filter_cached_places_filters_by_sub_radius_and_sorts_by_distance(): void
    {
        $places = [
            [
                'id' => 'place-far',
                'displayName' => ['text' => 'Far Toilet (800m)'],
                'location' => ['latitude' => 52.5270, 'longitude' => 13.4000],
            ],
            [
                'id' => 'place-near',
                'displayName' => ['text' => 'Near Toilet (50m)'],
                'location' => ['latitude' => 52.5201, 'longitude' => 13.4005],
            ],
            [
                'id' => 'place-medium',
                'displayName' => ['text' => 'Medium Toilet (250m)'],
                'location' => ['latitude' => 52.5220, 'longitude' => 13.4000],
            ],
            [
                'id' => 'place-too-far',
                'displayName' => ['text' => 'Out of Radius Toilet (2000m)'],
                'location' => ['latitude' => 52.5350, 'longitude' => 13.4000],
            ],
        ];

        // Target: 52.5200, 13.4000 with radius 500m
        $filtered = $this->service->filterCachedPlaces($places, 52.5200, 13.4000, 500.0);

        // 'place-too-far' and 'place-far' should be excluded
        $this->assertCount(2, $filtered);
        // 'place-near' should be first (nearest)
        $this->assertEquals('place-near', $filtered[0]['id']);
        // 'place-medium' should be second
        $this->assertEquals('place-medium', $filtered[1]['id']);
    }

    public function test_find_enclosing_cache_respects_truncation_when_max_results_reached(): void
    {
        // 10 places in a dense cluster (all within 60m of center), but requested radius was 250m
        $places = [];
        for ($i = 1; $i <= 10; $i++) {
            $places[] = [
                'id' => "place-{$i}",
                'displayName' => ['text' => "Place {$i}"],
                'location' => [
                    'latitude' => 52.5200 + ($i * 0.00005), // ~5.5m steps up to ~55m
                    'longitude' => 13.4000,
                ],
            ];
        }

        $cached = $this->service->store(52.5200, 13.4000, 250.0, $places, ['maxResultCount' => 10]);

        $effectiveRadius = $this->service->getEffectiveRadiusMeters($cached);
        $this->assertLessThan(100.0, $effectiveRadius);

        // Point 150m away with radius 40m -> (150 + 40 = 190m <= 250m requested, BUT > 100m effective) -> MUST BE CACHE MISS!
        $miss = $this->service->findEnclosingCache(52.5215, 13.4000, 40.0);
        $this->assertNull($miss);

        // Point 10m away with radius 20m -> (10 + 20 = 30m <= effectiveRadius) -> CACHE HIT!
        $hit = $this->service->findEnclosingCache(52.5201, 13.4000, 20.0);
        $this->assertNotNull($hit);
    }
}

