<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\GoogleApiLog;
use App\Models\GoogleNearbySearchCache;
use App\Models\Place;
use App\Models\Type;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NearestPlacesTest extends TestCase
{
    private array $createdCacheIds = [];

    private array $createdTypeIds = [];

    private array $createdPlaceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['wcinfo.google.api_key' => 'test_api_key_123']);
        GoogleApiLog::truncate();
        GoogleNearbySearchCache::truncate();
        AppSetting::truncate();
    }

    protected function tearDown(): void
    {
        if (! empty($this->createdCacheIds)) {
            GoogleNearbySearchCache::whereIn('id', $this->createdCacheIds)->delete();
        }

        if (! empty($this->createdTypeIds)) {
            Type::whereIn('id', $this->createdTypeIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_nearest_places_returns_cached_results_ordered_by_priorize_and_distance(): void
    {
        $centerLat = 52.5200;
        $centerLon = 13.4050;

        // Ensure types exist with priorize flags
        $insuranceType = Type::updateOrCreate(['type' => 'test_insurance'], ['priorize' => 0]);
        $cafeType = Type::updateOrCreate(['type' => 'test_cafe'], ['priorize' => 1]);
        $toiletType = Type::updateOrCreate(['type' => 'test_public_bathroom'], ['priorize' => 1]);
        $barType = Type::updateOrCreate(['type' => 'test_bar'], ['priorize' => 1]);

        $this->createdTypeIds = array_merge($this->createdTypeIds, [$insuranceType->id, $cafeType->id, $toiletType->id, $barType->id]);

        // Place 1: Insurance Agency at ~10m (priorize = 0)
        // 0.0001 deg lat is ~11.1 meters
        $place1 = [
            'id' => 'place_insurance_1',
            'displayName' => ['text' => 'Insurance Agency', 'languageCode' => 'de'],
            'formattedAddress' => 'Teststr. 1, Berlin',
            'location' => [
                'latitude' => 52.52009,
                'longitude' => 13.4050,
            ],
            'types' => ['test_insurance', 'point_of_interest'],
        ];

        // Place 2: Cafe at ~20m (priorize = 1)
        $place2 = [
            'id' => 'place_cafe_2',
            'displayName' => ['text' => 'Cafe Central', 'languageCode' => 'de'],
            'formattedAddress' => 'Teststr. 2, Berlin',
            'location' => [
                'latitude' => 52.52018,
                'longitude' => 13.4050,
            ],
            'types' => ['test_cafe', 'food'],
        ];

        // Place 3: Public Bathroom at ~30m (priorize = 1)
        $place3 = [
            'id' => 'place_toilet_3',
            'displayName' => ['text' => 'Public Restroom', 'languageCode' => 'de'],
            'formattedAddress' => 'Teststr. 3, Berlin',
            'location' => [
                'latitude' => 52.52027,
                'longitude' => 13.4050,
            ],
            'types' => ['test_public_bathroom'],
        ];

        // Place 4: Bar at ~90m (priorize = 1) - outside 40m default radius
        $place4 = [
            'id' => 'place_bar_4',
            'displayName' => ['text' => 'Late Bar', 'languageCode' => 'de'],
            'formattedAddress' => 'Teststr. 4, Berlin',
            'location' => [
                'latitude' => 52.52080,
                'longitude' => 13.4050,
            ],
            'types' => ['test_bar'],
        ];

        // Create fresh spatial cache (within 30 days) with radius 100m
        $cache = GoogleNearbySearchCache::create([
            'lat' => $centerLat,
            'lon' => $centerLon,
            'radius_meters' => 100.0,
            'query_params' => ['maxResultCount' => 20],
            'response_places' => [$place1, $place2, $place3, $place4],
            'result_count' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdCacheIds[] = $cache->id;

        // 1. Default request: radius = 40m, limit = 3
        $response = $this->getJson("/places/nearest/{$centerLat}/{$centerLon}");

        $response->assertOk()
            ->assertJsonPath('status', 'okay')
            ->assertJsonCount(3, 'places');

        $places = $response->json('places');

        // Ordering should prefer priorize=1 first, then sorted by distance ascending:
        // 1st: Cafe (priorize=1, ~20m)
        // 2nd: Public Restroom (priorize=1, ~30m)
        // 3rd: Insurance Agency (priorize=0, ~10m)
        $this->assertSame('place_cafe_2', $places[0]['id']);
        $this->assertSame('place_toilet_3', $places[1]['id']);
        $this->assertSame('place_insurance_1', $places[2]['id']);

        // Check distance property in meters
        $this->assertArrayHasKey('distance', $places[0]);
        $this->assertArrayHasKey('distance', $places[1]);
        $this->assertArrayHasKey('distance', $places[2]);
        $this->assertEqualsWithDelta(20.0, (float) $places[0]['distance'], 2.0);
        $this->assertEqualsWithDelta(30.0, (float) $places[1]['distance'], 2.0);
        $this->assertEqualsWithDelta(10.0, (float) $places[2]['distance'], 2.0);

        // 2. Limit parameter: limit = 2
        $responseLimit2 = $this->getJson("/places/nearest/{$centerLat}/{$centerLon}?limit=2");
        $responseLimit2->assertOk()
            ->assertJsonCount(2, 'places');
        $this->assertSame('place_cafe_2', $responseLimit2->json('places.0.id'));
        $this->assertSame('place_toilet_3', $responseLimit2->json('places.1.id'));

        // 3. Radius parameter: radius = 100m, limit = 4
        // Place 4 (Bar, priorize=1, ~90m) should now be included before Insurance Agency
        $responseRadius100 = $this->getJson("/places/nearest/{$centerLat}/{$centerLon}?radius=100&limit=4");
        $responseRadius100->assertOk()
            ->assertJsonCount(4, 'places');

        $places100 = $responseRadius100->json('places');
        $this->assertSame('place_cafe_2', $places100[0]['id']);
        $this->assertSame('place_toilet_3', $places100[1]['id']);
        $this->assertSame('place_bar_4', $places100[2]['id']);
        $this->assertSame('place_insurance_1', $places100[3]['id']);
        $this->assertEqualsWithDelta(89.0, (float) $places100[2]['distance'], 2.0);
    }

    public function test_nearest_places_calls_google_places_api_when_no_cache_or_cache_is_stale(): void
    {
        $lat = 48.1371;
        $lon = 11.5753;

        $cafeType = Type::updateOrCreate(['type' => 'test_api_cafe'], ['priorize' => 1]);
        $this->createdTypeIds[] = $cafeType->id;

        $apiPlace = [
            'id' => 'place_from_google_api',
            'displayName' => ['text' => 'Munich Cafe', 'languageCode' => 'de'],
            'formattedAddress' => 'Marienplatz 1, Munich',
            'location' => [
                'latitude' => 48.13715,
                'longitude' => 11.57535,
            ],
            'types' => ['test_api_cafe'],
        ];

        Http::fake([
            'https://places.googleapis.com/v1/places:searchNearby' => Http::response([
                'places' => [$apiPlace],
            ], 200),
        ]);

        $response = $this->getJson("/places/nearest/{$lat}/{$lon}");

        $response->assertOk()
            ->assertJsonPath('status', 'okay')
            ->assertJsonCount(1, 'places')
            ->assertJsonPath('places.0.id', 'place_from_google_api');

        $this->assertArrayHasKey('distance', $response->json('places.0'));
        $this->assertIsNumeric($response->json('places.0.distance'));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['excludedTypes'])
                && $data['excludedTypes'] === GooglePlacesService::EXCLUDED_NEARBY_TYPES;
        });

        // Check that result was stored in google_nearby_search_cache table
        $savedCache = GoogleNearbySearchCache::whereBetween('lat', [$lat - 0.001, $lat + 0.001])
            ->whereBetween('lon', [$lon - 0.001, $lon + 0.001])
            ->first();

        $this->assertNotNull($savedCache);
        $this->createdCacheIds[] = $savedCache->id;
        $this->createdPlaceIds[] = 'place_from_google_api';
    }

    public function test_nearest_places_validates_coordinates_and_parameters(): void
    {
        // Invalid latitude
        $this->getJson('/places/nearest/invalid/13.4050')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Invalid coordinates');

        // Out of range latitude (> 90)
        $this->getJson('/places/nearest/95.0/13.4050')
            ->assertStatus(422);

        // Out of range longitude (> 180)
        $this->getJson('/places/nearest/52.52/190.0')
            ->assertStatus(422);

        // Invalid limit
        $this->getJson('/places/nearest/52.52/13.40?limit=0')
            ->assertStatus(400);

        // Invalid radius
        $this->getJson('/places/nearest/52.52/13.40?radius=-5')
            ->assertStatus(400);
    }

    public function test_nearest_places_prioritizes_public_bathroom_over_other_prioritized_types(): void
    {
        $centerLat = 52.5200;
        $centerLon = 13.4050;

        $cafeType = Type::updateOrCreate(['type' => 'test_pb_cafe'], ['priorize' => 1]);
        $insuranceType = Type::updateOrCreate(['type' => 'test_pb_insurance'], ['priorize' => 0]);
        $this->createdTypeIds = array_merge($this->createdTypeIds, [$cafeType->id, $insuranceType->id]);

        // Place 1: Insurance at ~5m (priorize = 0)
        $placeInsurance = [
            'id' => 'place_ins_5m',
            'displayName' => ['text' => 'Insurance Agency'],
            'location' => ['latitude' => 52.520045, 'longitude' => 13.4050],
            'types' => ['test_pb_insurance'],
        ];

        // Place 2: Cafe at ~10m (priorize = 1)
        $placeCafe = [
            'id' => 'place_cafe_10m',
            'displayName' => ['text' => 'Near Cafe'],
            'location' => ['latitude' => 52.52009, 'longitude' => 13.4050],
            'types' => ['test_pb_cafe'],
        ];

        // Place 3: Public Bathroom at ~25m (priorize = 2)
        $placeBathroom = [
            'id' => 'place_public_bathroom_25m',
            'displayName' => ['text' => 'Farther Public Bathroom'],
            'location' => ['latitude' => 52.520225, 'longitude' => 13.4050],
            'types' => ['public_bathroom'],
        ];

        $cache = GoogleNearbySearchCache::create([
            'lat' => $centerLat,
            'lon' => $centerLon,
            'radius_meters' => 100.0,
            'query_params' => ['maxResultCount' => 20],
            'response_places' => [$placeInsurance, $placeCafe, $placeBathroom],
            'result_count' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdCacheIds[] = $cache->id;

        $response = $this->getJson("/places/nearest/{$centerLat}/{$centerLon}?limit=3&radius=50");

        $response->assertOk()
            ->assertJsonPath('status', 'okay')
            ->assertJsonCount(3, 'places');

        $places = $response->json('places');

        // 1st: public_bathroom (priorize=2, ~25m)
        // 2nd: cafe (priorize=1, ~10m)
        // 3rd: insurance (priorize=0, ~5m)
        $this->assertSame('place_public_bathroom_25m', $places[0]['id']);
        $this->assertSame('place_cafe_10m', $places[1]['id']);
        $this->assertSame('place_ins_5m', $places[2]['id']);
    }

    public function test_nearest_places_ignores_places_with_excluded_types_from_cache(): void
    {
        $centerLat = 52.5200;
        $centerLon = 13.4050;

        $placeHotel = [
            'id' => 'place_hotel_5m',
            'displayName' => ['text' => 'Hotel 5m'],
            'location' => ['latitude' => 52.520045, 'longitude' => 13.4050],
            'types' => ['lodging'],
        ];

        $placePost = [
            'id' => 'place_post_10m',
            'displayName' => ['text' => 'Post 10m'],
            'location' => ['latitude' => 52.520090, 'longitude' => 13.4050],
            'types' => ['post_office'],
        ];

        $placeAtm = [
            'id' => 'place_atm_15m',
            'displayName' => ['text' => 'ATM 15m'],
            'location' => ['latitude' => 52.520135, 'longitude' => 13.4050],
            'types' => ['atm', 'finance'],
        ];

        $placeShipping = [
            'id' => 'place_shipping_20m',
            'displayName' => ['text' => 'Shipping 20m'],
            'location' => ['latitude' => 52.520180, 'longitude' => 13.4050],
            'types' => ['shipping_service'],
        ];

        $placeCafe = [
            'id' => 'place_cafe_25m',
            'displayName' => ['text' => 'Cafe 25m'],
            'location' => ['latitude' => 52.520225, 'longitude' => 13.4050],
            'types' => ['cafe'],
        ];

        $cache = GoogleNearbySearchCache::create([
            'lat' => $centerLat,
            'lon' => $centerLon,
            'radius_meters' => 100.0,
            'query_params' => ['maxResultCount' => 20],
            'response_places' => [$placeHotel, $placePost, $placeAtm, $placeShipping, $placeCafe],
            'result_count' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdCacheIds[] = $cache->id;

        $response = $this->getJson("/places/nearest/{$centerLat}/{$centerLon}?limit=5&radius=50");

        $response->assertOk()
            ->assertJsonPath('status', 'okay')
            ->assertJsonCount(1, 'places');

        $places = $response->json('places');
        $this->assertSame('place_cafe_25m', $places[0]['id']);
    }
}
