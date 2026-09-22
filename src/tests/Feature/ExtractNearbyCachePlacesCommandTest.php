<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GoogleNearbySearchCache;
use App\Models\Place;
use App\Models\Toilet;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExtractNearbyCachePlacesCommandTest extends TestCase
{
    private array $createdPlaceIds = [];

    private array $createdCacheIds = [];

    private array $createdToiletIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            \App\Models\ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        if (! empty($this->createdCacheIds)) {
            GoogleNearbySearchCache::whereIn('id', $this->createdCacheIds)->delete();
        }

        parent::tearDown();
    }

    public function test_command_runs_successfully_when_no_cache_records_exist(): void
    {
        // Ensure no cache records for this test run
        GoogleNearbySearchCache::query()->delete();

        $this->artisan('app:extract-nearby-cache-places')
            ->assertSuccessful()
            ->expectsOutputToContain('No cached nearby searches found');
    }

    public function test_command_adds_new_places_from_nearby_cache(): void
    {
        $placeId = 'place_test_new_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $cache = GoogleNearbySearchCache::create([
            'lat' => 52.5200,
            'lon' => 13.4050,
            'radius_meters' => 500.0,
            'result_count' => 1,
            'response_places' => [
                [
                    'id' => $placeId,
                    'displayName' => ['text' => 'New Test Cafe', 'languageCode' => 'de'],
                    'formattedAddress' => 'Alexanderplatz 1, Berlin',
                    'location' => ['latitude' => 52.5210, 'longitude' => 13.4060],
                    'websiteUri' => 'https://test-cafe.de',
                    'regularOpeningHours' => [
                        'periods' => [
                            [
                                'open' => ['day' => 1, 'hour' => 8, 'minute' => 0],
                                'close' => ['day' => 1, 'hour' => 20, 'minute' => 0],
                            ],
                        ],
                    ],
                    'types' => ['cafe', 'food', 'point_of_interest'],
                ],
            ],
        ]);
        $this->createdCacheIds[] = $cache->id;

        // 1. Dry run: Place must not be created
        $this->artisan('app:extract-nearby-cache-places', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode');

        $this->assertNull(Place::find($placeId));

        // 2. Live run: Place must be created with all fields
        $this->artisan('app:extract-nearby-cache-places')
            ->assertSuccessful()
            ->expectsOutputToContain('Place extraction and synchronization completed successfully');

        $createdPlace = Place::find($placeId);
        $this->assertNotNull($createdPlace);
        $this->assertSame('New Test Cafe', $createdPlace->getName());
        $this->assertSame(52.521, $createdPlace->getLat());
        $this->assertSame(13.406, $createdPlace->getLon());

        $data = $createdPlace->data;
        $this->assertSame('https://test-cafe.de', $data['websiteUri']);
        $this->assertNotEmpty($data['regularOpeningHours']['periods']);
        $this->assertSame(1, $data['regularOpeningHours']['periods'][0]['open']['day']);
    }

    public function test_command_updates_existing_place_missing_opening_hours_and_website(): void
    {
        $placeId = 'place_test_update_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        // Existing place in places table: legacy/partial data missing opening hours & website
        $existingPlace = Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Partial Info Place', 'languageCode' => 'de'],
                'formattedAddress' => 'Musterstr. 12, München',
                'location' => ['latitude' => 48.137, 'longitude' => 11.575],
            ],
            'updated' => now()->subDays(60),
        ]);

        // Cache entry contains opening hours and website
        $cache = GoogleNearbySearchCache::create([
            'lat' => 48.137,
            'lon' => 11.575,
            'radius_meters' => 300.0,
            'result_count' => 1,
            'response_places' => [
                [
                    'id' => $placeId,
                    'displayName' => ['text' => 'Partial Info Place', 'languageCode' => 'de'],
                    'websiteUri' => 'https://munich-place.de',
                    'regularOpeningHours' => [
                        'periods' => [
                            [
                                'open' => ['day' => 2, 'hour' => 9, 'minute' => 30],
                                'close' => ['day' => 2, 'hour' => 18, 'minute' => 0],
                            ],
                        ],
                    ],
                    'types' => ['store'],
                ],
            ],
        ]);
        $this->createdCacheIds[] = $cache->id;

        // Run command
        $this->artisan('app:extract-nearby-cache-places')
            ->assertSuccessful();

        // Verify updated place
        $existingPlace->refresh();
        $updatedData = $existingPlace->data;

        $this->assertSame('https://munich-place.de', $updatedData['websiteUri']);
        $this->assertNotEmpty($updatedData['regularOpeningHours']['periods']);
        $this->assertSame(9, $updatedData['regularOpeningHours']['periods'][0]['open']['hour']);
        $this->assertSame(30, $updatedData['regularOpeningHours']['periods'][0]['open']['minute']);
        // Original fields preserved
        $this->assertSame('Musterstr. 12, München', $updatedData['formattedAddress']);
    }

    public function test_command_handles_duplicate_places_across_multiple_cache_rows(): void
    {
        $placeId = 'place_test_multi_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        // Cache row 1 has basic name & location
        $cache1 = GoogleNearbySearchCache::create([
            'lat' => 50.110,
            'lon' => 8.682,
            'radius_meters' => 200.0,
            'result_count' => 1,
            'response_places' => [
                [
                    'id' => $placeId,
                    'displayName' => ['text' => 'Frankfurt Station Shop', 'languageCode' => 'de'],
                    'location' => ['latitude' => 50.1109, 'longitude' => 8.6821],
                ],
            ],
        ]);
        $this->createdCacheIds[] = $cache1->id;

        // Cache row 2 has opening hours & website
        $cache2 = GoogleNearbySearchCache::create([
            'lat' => 50.111,
            'lon' => 8.683,
            'radius_meters' => 400.0,
            'result_count' => 1,
            'response_places' => [
                [
                    'id' => $placeId,
                    'websiteUri' => 'https://frankfurt-station-shop.de',
                    'regularOpeningHours' => [
                        'periods' => [
                            [
                                'open' => ['day' => 0, 'hour' => 0, 'minute' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->createdCacheIds[] = $cache2->id;

        $this->artisan('app:extract-nearby-cache-places')
            ->assertSuccessful();

        $place = Place::find($placeId);
        $this->assertNotNull($place);
        $data = $place->data;

        $this->assertSame('Frankfurt Station Shop', $data['displayName']['text']);
        $this->assertSame('https://frankfurt-station-shop.de', $data['websiteUri']);
        $this->assertNotEmpty($data['regularOpeningHours']['periods']);
    }

    public function test_command_syncs_toilets_when_flag_provided(): void
    {
        $placeId = 'place_test_toilet_sync_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $toilet = Toilet::create([
            'name' => 'Toilet for Sync Test',
            'place_id' => $placeId,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $cache = GoogleNearbySearchCache::create([
            'lat' => 53.551,
            'lon' => 9.993,
            'radius_meters' => 500.0,
            'result_count' => 1,
            'response_places' => [
                [
                    'id' => $placeId,
                    'displayName' => ['text' => 'Hamburg Place', 'languageCode' => 'de'],
                    'websiteUri' => 'https://hamburg-toilet-place.de',
                    'regularOpeningHours' => [
                        'periods' => [
                            [
                                'open' => ['day' => 1, 'hour' => 6, 'minute' => 0],
                                'close' => ['day' => 1, 'hour' => 22, 'minute' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->createdCacheIds[] = $cache->id;

        $this->artisan('app:extract-nearby-cache-places', ['--update-toilets' => true])
            ->assertSuccessful();

        $websiteProp = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'website')
            ->first();

        $this->assertNotNull($websiteProp);
        $this->assertSame('https://hamburg-toilet-place.de', $websiteProp->value);

        $hoursProp = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'place_opening_hours')
            ->first();

        $this->assertNotNull($hoursProp);
        $this->assertStringContainsString('"day":1', $hoursProp->value);

        // Verify revision was recorded
        $revision = \App\Models\ToiletRevision::where('toilet_id', $toilet->id)->latest('id')->first();
        $this->assertNotNull($revision);
        $this->assertSame('extract-nearby-cache', $revision->source);
        $this->assertNotNull($revision->diff);
        $this->assertArrayHasKey('website', $revision->diff);
        $this->assertArrayHasKey('place_opening_hours', $revision->diff);
        $this->assertSame('https://hamburg-toilet-place.de', $revision->diff['website']['new']);

        $toilet->refresh();
        $this->assertNotNull($toilet->last_diff);
        $this->assertArrayHasKey('website', $toilet->last_diff);
    }
}
