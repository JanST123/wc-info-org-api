<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\GoogleApiLog;
use App\Models\GoogleNearbySearchCache;
use App\Services\GoogleCostService;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleNearbyCacheFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GoogleApiLog::truncate();
        GoogleNearbySearchCache::truncate();
        AppSetting::truncate();
    }

    public function test_nearby_search_caches_response_and_reuses_enclosed_cache(): void
    {
        config(['wcinfo.google.api_key' => 'test-api-key']);

        Http::fake([
            'https://places.googleapis.com/v1/places:searchNearby' => Http::response([
                'places' => [
                    [
                        'id' => 'place-alexanderplatz-1',
                        'displayName' => ['text' => 'Alexanderplatz Toilet 1'],
                        'location' => ['latitude' => 52.5219, 'longitude' => 13.4132],
                        'types' => ['public_bathroom'],
                    ],
                    [
                        'id' => 'place-hackescher-2',
                        'displayName' => ['text' => 'Hackescher Markt Toilet 2'],
                        'location' => ['latitude' => 52.5225, 'longitude' => 13.4020],
                        'types' => ['toilet'],
                    ],
                ],
            ], 200),
        ]);

        /** @var GooglePlacesService $placesService */
        $placesService = $this->app->make(GooglePlacesService::class);
        /** @var GoogleCostService $costService */
        $costService = $this->app->make(GoogleCostService::class);

        // 1. Initial Request (Radius: 2.0 km) -> Cache Miss -> HTTP Request Made
        $results1 = $placesService->nearbySearchRaw(52.521918, 13.413215, 2.0);
        $this->assertCount(2, $results1);

        Http::assertSentCount(1);
        $this->assertEquals(1, GoogleNearbySearchCache::count());
        $this->assertEquals(1, GoogleApiLog::count());

        $firstLog = GoogleApiLog::first();
        $this->assertFalse((bool) $firstLog->is_cache_hit);
        $this->assertEquals(0.032, $firstLog->cost_usd);

        // 2. Second Request inside the cached area (Radius: 0.5 km) -> Cache Hit -> NO HTTP Request!
        $results2 = $placesService->nearbySearchRaw(52.521918, 13.413215, 0.5);
        $this->assertCount(1, $results2); // Only 'place-alexanderplatz-1' is within 500m
        $this->assertEquals('place-alexanderplatz-1', $results2[0]['id']);

        // HTTP sent count remains 1!
        Http::assertSentCount(1);
        $this->assertEquals(1, GoogleNearbySearchCache::count());
        $this->assertEquals(2, GoogleApiLog::count());

        $secondLog = GoogleApiLog::orderByDesc('id')->first();
        $this->assertTrue((bool) $secondLog->is_cache_hit);
        $this->assertEquals(0.000, $secondLog->cost_usd);

        // 3. Cost Statistics Verification
        $stats = $costService->getMonthlyStats();
        $this->assertEquals(0.032, $stats['total_cost']); // Only 1 billed call!
        $this->assertEquals(2, $stats['total_requests']);
        $this->assertEquals(1, $stats['api_calls']);
        $this->assertEquals(1, $stats['cache_hits']);
        $this->assertEquals(50.0, $stats['cache_hit_rate']);
        $this->assertEquals(0.032, $stats['saved_cost']); // 1 cache hit * $0.032 saved

        // 4. Force refresh bypasses cache
        $results3 = $placesService->nearbySearchRaw(52.521918, 13.413215, 0.5, forceRefresh: true);
        Http::assertSentCount(2);
        $this->assertEquals(3, GoogleApiLog::count());
    }

    public function test_admin_costs_dashboard_shows_saved_costs_and_cache_hits(): void
    {
        /** @var GoogleCostService $costService */
        $costService = $this->app->make(GoogleCostService::class);

        // 1 API call ($0.032)
        $costService->logApiCall(GoogleCostService::SERVICE_PLACES_NEARBY, 'https://places.googleapis.com/v1/places:searchNearby', 0.032, 200, ['test' => 1], isCacheHit: false);
        // 2 Cache hits ($0.00)
        $costService->logApiCall(GoogleCostService::SERVICE_PLACES_NEARBY, 'https://places.googleapis.com/v1/places:searchNearby', 0.0, 200, ['cached' => true], isCacheHit: true);
        $costService->logApiCall(GoogleCostService::SERVICE_PLACES_NEARBY, 'https://places.googleapis.com/v1/places:searchNearby', 0.0, 200, ['cached' => true], isCacheHit: true);

        $response = $this->withSession(['admin_logged_in' => true])
            ->get('/admin/costs');

        $response->assertStatus(200);
        $response->assertSee('Intelligent Caching & Saved Costs', false);
        $response->assertSee('66.7% Cache Hit Rate');
        $response->assertSee('+$0.06');
        $response->assertSee('Cache Hit ($0.00)');
        $response->assertSee('API Call ($0.032)');
    }
}
