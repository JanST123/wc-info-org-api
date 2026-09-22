<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletRevision;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DiscoverPlacesCommandTest extends TestCase
{
    private array $createdToiletIds = [];

    private array $createdPlaceIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_discovers_only_toilets_that_were_included_but_not_yet_discovered(): void
    {
        $now = now();

        $includedButNotDiscovered = Toilet::create([
            'name' => 'WC #1',
            'owner' => 'Cafe A',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_a',
            'status' => 'active',
            'last_included' => $now,
            'last_discovered' => null,
        ]);
        $this->createdToiletIds[] = $includedButNotDiscovered->id;

        $t2 = Toilet::create([
            'name' => 'WC #2',
            'owner' => 'Cafe B',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_b',
            'status' => 'active',
            'last_included' => $now,
            'last_discovered' => $now,
        ]);
        $this->createdToiletIds[] = $t2->id;

        $t3 = Toilet::create([
            'name' => 'WC #3',
            'owner' => 'Cafe C',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_c',
            'status' => 'active',
            'last_included' => $now,
            'last_discovered' => $now->copy()->subHour(),
        ]);
        $this->createdToiletIds[] = $t3->id;

        $status = Artisan::call('app:discover-places', ['--dry-run' => true, '--limit' => 500]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Found', $output);
        $this->assertStringContainsString('toilets to discover.', $output);
        $this->assertStringContainsString("toilet_id={$includedButNotDiscovered->id} place_id=place_a", $output);
        $this->assertStringContainsString('place_id=place_c', $output);
        $this->assertStringNotContainsString('place_id=place_b', $output);
    }

    public function test_logs_debug_details_when_existing_toilet_is_updated(): void
    {
        Log::spy();

        $placeId = 'place_log_test_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->method('fetchPlaceDetails')
            ->willReturn([
                'id' => $placeId,
                'displayName' => ['text' => 'New Owner Name'],
                'location' => ['latitude' => 52.8, 'longitude' => 13.8],
                'formattedAddress' => 'Updated Address 123',
            ]);

        $this->app->instance(GooglePlacesService::class, $placesService);

        $toilet = Toilet::create([
            'name' => 'WC Existing',
            'owner' => 'Old Owner Name',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'last_included' => now()->addDays(10),
            'last_discovered' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $status = Artisan::call('app:discover-places', ['--limit' => 1]);

        Log::shouldHaveReceived('debug')
            ->with(
                \Mockery::pattern('/Updated toilet '.$toilet->id.'/'),
                \Mockery::on(function ($context) use ($toilet, $placeId) {
                    return $context['toilet_id'] === $toilet->id
                        && $context['place_id'] === $placeId
                        && isset($context['changes']['owner'])
                        && $context['changes']['owner']['old'] === 'Old Owner Name'
                        && $context['changes']['owner']['new'] === 'New Owner Name';
                })
            );
    }

    public function test_prefer_cache_uses_existing_place_data_without_querying_google_api(): void
    {
        $placeId = 'place_cached_test_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Cached DB Owner Name'],
                'location' => ['latitude' => 52.5, 'longitude' => 13.5],
                'formattedAddress' => 'Cached Street 42',
            ],
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('fetchPlaceDetails');
        $this->app->instance(GooglePlacesService::class, $placesService);

        $toilet = Toilet::create([
            'name' => 'WC Prefer Cache',
            'owner' => 'Initial Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'last_included' => now()->addDays(20),
            'last_discovered' => null,
            'last_places_fetch' => now()->subMonths(2), // Cache age is older than 1 month
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $status = Artisan::call('app:discover-places', ['--prefer-cache' => true, '--limit' => 1]);
        $this->assertSame(0, $status);

        $toilet->refresh();
        $this->assertSame('Cached DB Owner Name', $toilet->owner);
        $this->assertNotNull($toilet->last_discovered);
    }

    public function test_prefer_cache_falls_back_to_google_api_when_place_not_in_database(): void
    {
        $placeId = 'place_fallback_test_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->once())
            ->method('fetchPlaceDetails')
            ->with($placeId)
            ->willReturn([
                'id' => $placeId,
                'displayName' => ['text' => 'Fetched From Google'],
                'location' => ['latitude' => 52.6, 'longitude' => 13.6],
                'formattedAddress' => 'API Street 99',
                'types' => ['point_of_interest'],
            ]);
        $this->app->instance(GooglePlacesService::class, $placesService);

        $toilet = Toilet::create([
            'name' => 'WC Fallback Test',
            'owner' => 'Initial Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'last_included' => now()->addDays(30),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $status = Artisan::call('app:discover-places', ['--prefer-cache' => true, '--limit' => 1]);
        $this->assertSame(0, $status);

        $toilet->refresh();
        $this->assertSame('Fetched From Google', $toilet->owner);
        $this->assertNotNull(Place::where('place_id', $placeId)->first());
    }

    public function test_prefer_cache_defaults_to_30d_and_uses_fresh_cache_without_api_call(): void
    {
        $placeId = 'place_age_test_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Cached Age Test'],
                'location' => ['latitude' => 52.7, 'longitude' => 13.7],
            ],
            'updated' => now()->subDays(5),
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('fetchPlaceDetails');
        $this->app->instance(GooglePlacesService::class, $placesService);

        // Toilet rescheduled (last_included is now, last_discovered is null)
        $toilet = Toilet::create([
            'name' => 'WC Recent Fetch',
            'owner' => 'Initial Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'last_included' => now()->addDays(40),
            'last_discovered' => null,
            'last_places_fetch' => now()->subDays(5),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // By default without flags, prefer-cache defaults to 30d and processes the toilet using cache
        $status = Artisan::call('app:discover-places', ['--dry-run' => true, '--limit' => 500]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("toilet_id={$toilet->id}", $output);
        $this->assertStringContainsString('[CACHED]', $output);
    }

    public function test_dry_run_cost_prediction_without_prefer_cache(): void
    {
        $placeId1 = 'place_pred_1_'.uniqid();
        $placeId2 = 'place_pred_2_'.uniqid();
        $this->createdPlaceIds[] = $placeId1;
        $this->createdPlaceIds[] = $placeId2;

        $t1 = Toilet::create([
            'name' => 'WC Pred #1',
            'owner' => 'Owner 1',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId1,
            'status' => 'active',
            'last_included' => now()->addDays(100),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $t1->id;

        $t2 = Toilet::create([
            'name' => 'WC Pred #2',
            'owner' => 'Owner 2',
            'lat' => 52.1,
            'lon' => 13.1,
            'place_id' => $placeId2,
            'status' => 'active',
            'last_included' => now()->addDays(99),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $t2->id;

        $status = Artisan::call('app:discover-places', ['--dry-run' => true, '--limit' => 2]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('predicted_api_calls: 2', $output);
        $this->assertStringContainsString('predicted_cached_places: 0', $output);
        $this->assertStringContainsString('predicted_cost_usd: 0.034', $output);
        $this->assertStringContainsString('Cost Prediction: 2 Google API call(s) required (~$0.034 USD)', $output);
        $this->assertStringContainsString('[API CALL]', $output);
    }

    public function test_dry_run_cost_prediction_with_prefer_cache(): void
    {
        $placeIdCached = 'place_cached_'.uniqid();
        $placeIdUncached = 'place_uncached_'.uniqid();
        $this->createdPlaceIds[] = $placeIdCached;
        $this->createdPlaceIds[] = $placeIdUncached;

        Place::create([
            'place_id' => $placeIdCached,
            'data' => [
                'id' => $placeIdCached,
                'displayName' => ['text' => 'Cached Name'],
                'location' => ['latitude' => 52.0, 'longitude' => 13.0],
            ],
        ]);

        $t1 = Toilet::create([
            'name' => 'WC Cached',
            'owner' => 'Owner Cached',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeIdCached,
            'status' => 'active',
            'last_included' => now()->addDays(100),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $t1->id;

        $t2 = Toilet::create([
            'name' => 'WC Uncached',
            'owner' => 'Owner Uncached',
            'lat' => 52.1,
            'lon' => 13.1,
            'place_id' => $placeIdUncached,
            'status' => 'active',
            'last_included' => now()->addDays(99),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $t2->id;

        $status = Artisan::call('app:discover-places', ['--dry-run' => true, '--prefer-cache' => true, '--limit' => 2]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('predicted_api_calls: 1', $output);
        $this->assertStringContainsString('predicted_cached_places: 1', $output);
        $this->assertStringContainsString('predicted_cost_usd: 0.017', $output);
        $this->assertStringContainsString('Cost Prediction: 1 Google API call(s) required (~$0.017 USD)', $output);
        $this->assertStringContainsString('Prefer-cache saved 1 API call(s)', $output);
        $this->assertStringContainsString('[CACHED]', $output);
        $this->assertStringContainsString('[API CALL]', $output);
    }

    public function test_dry_run_cost_prediction_reuses_cache_for_duplicate_places_with_prefer_cache(): void
    {
        $sharedPlaceId = 'place_shared_'.uniqid();
        $this->createdPlaceIds[] = $sharedPlaceId;

        $t1 = Toilet::create([
            'name' => 'WC Shared 1',
            'owner' => 'Owner 1',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $sharedPlaceId,
            'status' => 'active',
            'last_included' => now()->addDays(100),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $t1->id;

        $t2 = Toilet::create([
            'name' => 'WC Shared 2',
            'owner' => 'Owner 2',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $sharedPlaceId,
            'status' => 'active',
            'last_included' => now()->addDays(99),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $t2->id;

        $status = Artisan::call('app:discover-places', ['--dry-run' => true, '--prefer-cache' => true, '--limit' => 2]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('predicted_api_calls: 1', $output);
        $this->assertStringContainsString('predicted_cached_places: 1', $output);
        $this->assertStringContainsString('predicted_cost_usd: 0.017', $output);
    }

    public function test_prefer_cache_with_max_age_uses_fresh_cache(): void
    {
        $placeId = 'place_fresh_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Fresh Cached Name'],
                'location' => ['latitude' => 52.0, 'longitude' => 13.0],
            ],
            'updated' => now()->subDays(10), // 10 days old < 30 days
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('fetchPlaceDetails');
        $this->app->instance(GooglePlacesService::class, $placesService);

        $toilet = Toilet::create([
            'name' => 'WC Fresh Test',
            'owner' => 'Old Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'last_included' => now()->addDays(100),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $status = Artisan::call('app:discover-places', ['--prefer-cache' => '30d', '--limit' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('max_cache_age: 30d', $output);
        $toilet->refresh();
        $this->assertSame('Fresh Cached Name', $toilet->owner);
    }

    public function test_prefer_cache_with_max_age_refreshes_stale_cache_from_google_api(): void
    {
        $placeId = 'place_stale_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Old Stale Name'],
                'location' => ['latitude' => 52.0, 'longitude' => 13.0],
            ],
            'updated' => now()->subDays(45), // 45 days old > 30 days cutoff
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->once())
            ->method('fetchPlaceDetails')
            ->with($placeId)
            ->willReturn([
                'id' => $placeId,
                'displayName' => ['text' => 'Refreshed Fresh Name'],
                'location' => ['latitude' => 52.0, 'longitude' => 13.0],
            ]);
        $this->app->instance(GooglePlacesService::class, $placesService);

        $toilet = Toilet::create([
            'name' => 'WC Stale Test',
            'owner' => 'Initial Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'last_included' => now()->addDays(100),
            'last_discovered' => null,
            'last_places_fetch' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $status = Artisan::call('app:discover-places', ['--prefer-cache' => '30d', '--limit' => 1]);
        $this->assertSame(0, $status);

        $toilet->refresh();
        $this->assertSame('Refreshed Fresh Name', $toilet->owner);

        $place = Place::where('place_id', $placeId)->first();
        $this->assertNotNull($place);
        $this->assertTrue($place->updated->gte(now()->subMinute()));
    }

    public function test_dry_run_cost_prediction_identifies_stale_cache_and_reports_in_prediction(): void
    {
        $freshPlaceId = 'place_fresh_pred_'.uniqid();
        $stalePlaceId = 'place_stale_pred_'.uniqid();
        $this->createdPlaceIds[] = $freshPlaceId;
        $this->createdPlaceIds[] = $stalePlaceId;

        Place::create([
            'place_id' => $freshPlaceId,
            'data' => ['id' => $freshPlaceId, 'displayName' => ['text' => 'Fresh Place']],
            'updated' => now()->subDays(5),
        ]);

        Place::create([
            'place_id' => $stalePlaceId,
            'data' => ['id' => $stalePlaceId, 'displayName' => ['text' => 'Stale Place']],
            'updated' => now()->subDays(60), // older than 30d
        ]);

        $t1 = Toilet::create([
            'name' => 'WC Fresh',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $freshPlaceId,
            'status' => 'active',
            'last_included' => now()->addDays(100),
            'last_discovered' => null,
        ]);
        $this->createdToiletIds[] = $t1->id;

        $t2 = Toilet::create([
            'name' => 'WC Stale',
            'lat' => 52.1,
            'lon' => 13.1,
            'place_id' => $stalePlaceId,
            'status' => 'active',
            'last_included' => now()->addDays(99),
            'last_discovered' => null,
        ]);
        $this->createdToiletIds[] = $t2->id;

        $status = Artisan::call('app:discover-places', [
            '--dry-run' => true,
            '--prefer-cache' => '30d',
            '--limit' => 2,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('max_cache_age: 30d', $output);
        $this->assertStringContainsString('predicted_api_calls: 1', $output);
        $this->assertStringContainsString('predicted_cached_places: 1', $output);
        $this->assertStringContainsString('predicted_cost_usd: 0.017', $output);
        $this->assertStringContainsString('[CACHED]', $output);
        $this->assertStringContainsString('[API CALL (stale cache)]', $output);
        $this->assertStringContainsString('with max cache age: 30d', $output);
    }

    public function test_prefer_cache_with_max_cache_age_companion_option(): void
    {
        $status = Artisan::call('app:discover-places', [
            '--dry-run' => true,
            '--max-cache-age' => '2w',
            '--limit' => 1,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('prefer_cache: true', $output);
        $this->assertStringContainsString('max_cache_age: 2w', $output);
    }

    public function test_removes_place_id_and_flags_toilet_when_google_api_returns_no_result(): void
    {
        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->method('fetchPlaceDetails')
            ->willReturn(null);

        $this->app->instance(GooglePlacesService::class, $placesService);

        $deadPlaceId = 'ChIJdeadplace_'.uniqid();

        $toilet = Toilet::create([
            'name' => 'Toilet With Dead Place',
            'owner' => 'Closed Cafe',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'place_id' => $deadPlaceId,
            'status' => 'active',
            'flagged' => 0,
            'last_included' => now()->addDays(5),
            'last_discovered' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $status = Artisan::call('app:discover-places', ['--limit' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("Google Place Details returned no result for toilet {$toilet->id}", $output);
        $this->assertStringContainsString('removed place_id and flagged for review', $output);
        $this->assertStringContainsString('unlinked_places: 1', $output);
        $this->assertStringContainsString('errors: 0', $output);

        // Check updated database state
        $toilet->refresh();
        $this->assertNull($toilet->place_id);
        $this->assertTrue((bool) $toilet->flagged);
        $this->assertNotNull($toilet->last_discovered);
        $this->assertNotNull($toilet->last_places_fetch);

        // Check last_diff
        $this->assertIsArray($toilet->last_diff);
        $this->assertSame($deadPlaceId, $toilet->last_diff['place_id']['old']);
        $this->assertNull($toilet->last_diff['place_id']['new']);
        $this->assertFalse($toilet->last_diff['flagged']['old']);
        $this->assertTrue($toilet->last_diff['flagged']['new']);

        // Check revision
        $revision = ToiletRevision::where('toilet_id', $toilet->id)->orderByDesc('version')->first();
        $this->assertNotNull($revision);
        $this->assertSame('discover-places-not-found', $revision->source);
        $this->assertNull($revision->toilet_data['place_id']);
        $this->assertTrue((bool) $revision->toilet_data['flagged']);
    }
}

