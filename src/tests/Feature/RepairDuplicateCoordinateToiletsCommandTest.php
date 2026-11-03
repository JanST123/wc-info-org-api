<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletRevision;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairDuplicateCoordinateToiletsCommandTest extends TestCase
{
    /**
     * @var array<int, int>
     */
    private array $createdToiletIds = [];

    /**
     * @var array<int, string>
     */
    private array $createdPlaceIds = [];

    /**
     * @var array<int, int>
     */
    private array $createdNearbyCacheIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdNearbyCacheIds)) {
            DB::table('google_nearby_search_cache')->whereIn('id', $this->createdNearbyCacheIds)->delete();
        }

        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $testLat = 85.1111111;
        $testLon = 85.2222222;

        $placeId1 = 'test_place_dry_run_1_'.uniqid();
        $placeId2 = 'test_place_dry_run_2_'.uniqid();
        $this->createdPlaceIds = [$placeId1, $placeId2];

        Place::create([
            'place_id' => $placeId1,
            'data' => [
                'location' => ['latitude' => 52.5100000, 'longitude' => 13.4100000],
            ],
            'updated' => now(),
        ]);

        $toilet1 = Toilet::create([
            'name' => 'Toilet 1',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => $placeId1,
            'status' => 'active',
            'version' => 1,
        ]);
        $toilet2 = Toilet::create([
            'name' => 'Toilet 2',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => null,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds = [$toilet1->id, $toilet2->id];

        // Revision for toilet2 containing earlier place_id
        ToiletRevision::create([
            'toilet_id' => $toilet2->id,
            'version' => 1,
            'source' => 'initial',
            'properties_data' => [],
            'toilet_data' => [
                'lat' => $testLat,
                'lon' => $testLon,
                'place_id' => $placeId2,
            ],
            'created_at' => now(),
        ]);

        $this->artisan('app:repair-duplicate-coordinate-toilets', [
            '--dry-run' => true,
            '--skip-api' => true,
            '--coords' => "{$testLat},{$testLon}",
        ])
            ->expectsOutputToContain('DRY-RUN MODE')
            ->expectsOutputToContain('DRY-RUN completed. Run without --dry-run to apply changes.')
            ->assertSuccessful();

        $toilet1->refresh();
        $toilet2->refresh();

        // Database should be unchanged
        $this->assertEquals($testLat, (float) $toilet1->lat);
        $this->assertEquals($testLon, (float) $toilet1->lon);
        $this->assertNull($toilet2->place_id);
        $this->assertEquals($testLat, (float) $toilet2->lat);
        $this->assertEquals($testLon, (float) $toilet2->lon);

        // No new revisions created
        $this->assertEquals(1, ToiletRevision::where('toilet_id', $toilet2->id)->count());
    }

    public function test_restores_place_id_and_updates_coordinates(): void
    {
        $testLat = 85.3333333;
        $testLon = 85.4444444;

        $placeId = 'test_restore_place_'.uniqid();
        $this->createdPlaceIds = [$placeId];

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'displayName' => ['text' => 'Cafe Central'],
                'location' => ['latitude' => 52.5200000, 'longitude' => 13.4050000],
            ],
            'updated' => now(),
        ]);

        $toilet1 = Toilet::create([
            'name' => 'Toilet With Removed Place',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => null,
            'status' => 'active',
            'flagged' => 1,
            'version' => 2,
        ]);
        $toilet2 = Toilet::create([
            'name' => 'Duplicate Partner Toilet',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => 'unrelated_place_'.uniqid(),
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds = [$toilet1->id, $toilet2->id];

        // Revision on toilet 1 with diff containing old place_id
        ToiletRevision::create([
            'toilet_id' => $toilet1->id,
            'version' => 2,
            'source' => 'discover-places-not-found',
            'properties_data' => [],
            'diff' => ['place_id' => ['old' => $placeId, 'new' => null]],
            'summary' => "Place {$placeId} not found in Google Places API; removed place_id and flagged for review",
            'toilet_data' => ['lat' => $testLat, 'lon' => $testLon, 'place_id' => null],
            'created_at' => now(),
        ]);

        $this->artisan('app:repair-duplicate-coordinate-toilets', [
            '--skip-api' => true,
            '--unflag' => true,
            '--coords' => "{$testLat},{$testLon}",
        ])
            ->expectsOutputToContain('Coordinate repair completed.')
            ->assertSuccessful();

        $toilet1->refresh();

        // 1. place_id should be restored
        $this->assertSame($placeId, $toilet1->place_id);

        // 2. Coordinates should be updated from place
        $this->assertEquals(52.52, (float) $toilet1->lat);
        $this->assertEquals(13.405, (float) $toilet1->lon);

        // 3. Flagged status should be cleared by --unflag
        $this->assertFalse((bool) $toilet1->flagged);

        // 4. Revisions should be recorded: restore_place_id and repair_coordinates
        $revisions = ToiletRevision::where('toilet_id', $toilet1->id)->orderBy('version')->get();
        $this->assertGreaterThanOrEqual(3, $revisions->count());

        $restoreRev = $revisions->firstWhere('source', 'restore_place_id');
        $this->assertNotNull($restoreRev);
        $this->assertSame($placeId, $restoreRev->toilet_data['place_id']);

        $repairRev = $revisions->firstWhere('source', 'repair_coordinates');
        $this->assertNotNull($repairRev);
        $this->assertEquals(52.52, (float) $repairRev->toilet_data['lat']);
        $this->assertEquals(13.405, (float) $repairRev->toilet_data['lon']);
    }

    public function test_restores_place_id_from_summary_regex_when_diff_is_missing(): void
    {
        $testLat = 85.9999991;
        $testLon = 85.9999992;

        $placeId = 'ChIJsummary_'.uniqid();
        $this->createdPlaceIds = [$placeId];

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'location' => ['latitude' => 50.1234567, 'longitude' => 8.1234567],
            ],
            'updated' => now(),
        ]);

        $toilet1 = Toilet::create([
            'name' => 'Toilet With Place In Summary Only',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => null,
            'status' => 'active',
            'version' => 1,
        ]);
        $toilet2 = Toilet::create([
            'name' => 'Partner Toilet',
            'lat' => $testLat,
            'lon' => $testLon,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds = [$toilet1->id, $toilet2->id];

        ToiletRevision::create([
            'toilet_id' => $toilet1->id,
            'version' => 1,
            'source' => 'discover-places-not-found',
            'properties_data' => [],
            'diff' => null,
            'summary' => "Place {$placeId} not found in Google Places API; removed place_id and flagged for review",
            'toilet_data' => ['lat' => $testLat, 'lon' => $testLon, 'place_id' => null],
            'created_at' => now(),
        ]);

        $this->artisan('app:repair-duplicate-coordinate-toilets', [
            '--skip-api' => true,
            '--coords' => "{$testLat},{$testLon}",
        ])
            ->assertSuccessful();

        $toilet1->refresh();
        $this->assertSame($placeId, $toilet1->place_id);
        $this->assertEquals(50.1234567, (float) $toilet1->lat);
        $this->assertEquals(8.1234567, (float) $toilet1->lon);
    }

    public function test_skips_user_overridden_coordinates_unless_force(): void
    {
        $testLat = 85.5555555;
        $testLon = 85.6666666;

        $placeId = 'test_override_place_'.uniqid();
        $this->createdPlaceIds = [$placeId];

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'location' => ['latitude' => 53.5500000, 'longitude' => 9.9900000],
            ],
            'updated' => now(),
        ]);

        $toilet1 = Toilet::create([
            'name' => 'Overridden Toilet',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => $placeId,
            'user_overridden' => ['lat', 'lon'],
            'status' => 'active',
            'version' => 1,
        ]);
        $toilet2 = Toilet::create([
            'name' => 'Partner Toilet',
            'lat' => $testLat,
            'lon' => $testLon,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds = [$toilet1->id, $toilet2->id];

        // Without --force: should be skipped
        $this->artisan('app:repair-duplicate-coordinate-toilets', [
            '--skip-api' => true,
            '--coords' => "{$testLat},{$testLon}",
        ])
            ->expectsOutputToContain('Skipped (user_overridden): 1')
            ->assertSuccessful();

        $toilet1->refresh();
        $this->assertEquals($testLat, (float) $toilet1->lat);
        $this->assertEquals($testLon, (float) $toilet1->lon);

        // With --force: should be updated
        $this->artisan('app:repair-duplicate-coordinate-toilets', [
            '--skip-api' => true,
            '--force' => true,
            '--coords' => "{$testLat},{$testLon}",
        ])
            ->assertSuccessful();

        $toilet1->refresh();
        $this->assertEquals(53.55, (float) $toilet1->lat);
        $this->assertEquals(9.99, (float) $toilet1->lon);
    }

    public function test_fetches_place_details_from_google_api_when_missing(): void
    {
        $testLat = 85.7777777;
        $testLon = 85.8888888;

        $placeId = 'test_google_fetch_place_'.uniqid();
        $this->createdPlaceIds = [$placeId];

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->once())
            ->method('fetchPlaceDetails')
            ->with($placeId)
            ->willReturn([
                'id' => $placeId,
                'location' => [
                    'latitude' => 48.137154,
                    'longitude' => 11.576124,
                ],
            ]);

        $this->app->instance(GooglePlacesService::class, $placesService);

        $toilet1 = Toilet::create([
            'name' => 'Toilet 1',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => $placeId,
            'status' => 'active',
            'version' => 1,
        ]);
        $toilet2 = Toilet::create([
            'name' => 'Toilet 2',
            'lat' => $testLat,
            'lon' => $testLon,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds = [$toilet1->id, $toilet2->id];

        $this->artisan('app:repair-duplicate-coordinate-toilets', [
            '--coords' => "{$testLat},{$testLon}",
        ])
            ->assertSuccessful();

        $toilet1->refresh();
        $this->assertEquals(48.137154, (float) $toilet1->lat);
        $this->assertEquals(11.576124, (float) $toilet1->lon);

        // Place should now be cached in places table
        $cachedPlace = Place::find($placeId);
        $this->assertNotNull($cachedPlace);
        $this->assertEquals(48.137154, $cachedPlace->getLat());
        $this->assertEquals(11.576124, $cachedPlace->getLon());
    }

    public function test_resolves_coordinates_from_google_nearby_search_cache(): void
    {
        $testLat = 86.1111111;
        $testLon = 86.2222222;

        $placeId = 'ChIJnearby_'.uniqid();
        $this->createdPlaceIds = [$placeId];

        // Store place in google_nearby_search_cache, but NOT in places table
        $cacheId = DB::table('google_nearby_search_cache')->insertGetId([
            'lat' => $testLat,
            'lon' => $testLon,
            'radius_meters' => 1000,
            'query_params' => json_encode(['languageCode' => 'de']),
            'response_places' => json_encode([
                [
                    'id' => $placeId,
                    'displayName' => ['text' => 'Nearby Cafe'],
                    'location' => [
                        'latitude' => 51.5074,
                        'longitude' => -0.1278,
                    ],
                ],
            ]),
            'result_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdNearbyCacheIds[] = $cacheId;

        $toilet1 = Toilet::create([
            'name' => 'Toilet With Nearby Cached Place',
            'lat' => $testLat,
            'lon' => $testLon,
            'place_id' => $placeId,
            'status' => 'active',
            'version' => 1,
        ]);
        $toilet2 = Toilet::create([
            'name' => 'Partner Toilet',
            'lat' => $testLat,
            'lon' => $testLon,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds = [$toilet1->id, $toilet2->id];

        // Ensure places table does not have this place before run
        $this->assertNull(Place::find($placeId));

        // Run with --skip-api so it CANNOT call Google API
        $this->artisan('app:repair-duplicate-coordinate-toilets', [
            '--skip-api' => true,
            '--coords' => "{$testLat},{$testLon}",
        ])
            ->expectsOutputToContain('Updated coords via nearby_cache')
            ->assertSuccessful();

        $toilet1->refresh();
        $this->assertEquals(51.5074, (float) $toilet1->lat);
        $this->assertEquals(-0.1278, (float) $toilet1->lon);

        // Place should also be cached in places table automatically
        $cachedPlace = Place::find($placeId);
        $this->assertNotNull($cachedPlace);
        $this->assertEquals(51.5074, $cachedPlace->getLat());
        $this->assertEquals(-0.1278, $cachedPlace->getLon());
    }
}
