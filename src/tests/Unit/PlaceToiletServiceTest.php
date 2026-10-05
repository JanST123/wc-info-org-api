<?php

namespace Tests\Unit;

use App\Models\Toilet;
use App\Models\ToiletProperty;
use App\Models\ToiletRevision;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use App\Services\ToiletRevisionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlaceToiletServiceTest extends TestCase
{
    private array $createdToiletIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        parent::tearDown();
    }

    public function test_update_respects_user_overridden_properties(): void
    {
        $toilet = Toilet::create([
            'name' => 'WC #1',
            'owner' => 'Old Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_override_test',
            'status' => 'active',
        ]);

        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet->id,
            'type' => 'website',
            'value' => 'https://user-provided.example',
            'user_overridden' => 1,
        ]);

        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet->id,
            'type' => 'address',
            'value' => 'Old Address',
            'user_overridden' => 0,
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->method('crawlWebsite')
            ->willReturn([
                'toiletType' => 'forall',
                'contactEmail' => 'crawl@example.com',
                'resultCount' => 5,
            ]);

        $service = new PlaceToiletService($placesService);

        $changes = [];
        $service->updateToiletFromPlace($toilet, [
            'place_id' => 'place_override_test',
            'name' => 'New Owner',
            'website' => 'https://google.example',
            'geometry' => ['location' => ['lat' => 53.0, 'lng' => 14.0]],
            'formatted_address' => 'New Address',
            'opening_hours' => ['periods' => [['open' => ['day' => 1, 'hour' => 9, 'minute' => 0]]]],
        ], null, $changes);

        $toilet->refresh();

        // Check changes array has old and new values recorded
        $this->assertArrayHasKey('owner', $changes);
        $this->assertSame(['old' => 'Old Owner', 'new' => 'New Owner'], $changes['owner']);
        $this->assertArrayHasKey('lat', $changes);
        $this->assertSame(52.0, $changes['lat']['old']);
        $this->assertSame(53.0, $changes['lat']['new']);
        $this->assertArrayHasKey('lon', $changes);
        $this->assertSame(13.0, $changes['lon']['old']);
        $this->assertSame(14.0, $changes['lon']['new']);
        $this->assertArrayHasKey('address', $changes);
        $this->assertSame(['old' => 'Old Address', 'new' => 'New Address'], $changes['address']);
        $this->assertArrayNotHasKey('website', $changes); // website was overridden

        // User-overridden website must stay unchanged.
        $website = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'website')
            ->first();
        $this->assertSame('https://user-provided.example', $website->value);

        // Non-overridden address should be updated.
        $address = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'address')
            ->first();
        $this->assertSame('New Address', $address->value);

        // Owner and coordinates updated because they are not user-overridden.
        $this->assertSame('New Owner', $toilet->owner);
        $this->assertSame(53.0, $toilet->lat);
        $this->assertSame(14.0, $toilet->lon);

        DB::table('toilet_properties')->where('fk_toiletId', $toilet->id)->delete();
        $toilet->delete();
    }

    public function test_update_keeps_user_overridden_coordinates(): void
    {
        $toilet = Toilet::create([
            'name' => 'WC #2',
            'owner' => 'Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_coord_test',
            'status' => 'active',
            'user_overridden' => ['lat', 'lon'],
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->method('crawlWebsite')
            ->willReturn([
                'toiletType' => 'forall',
                'contactEmail' => '',
                'resultCount' => 1,
            ]);

        $service = new PlaceToiletService($placesService);

        $service->updateToiletFromPlace($toilet, [
            'place_id' => 'place_coord_test',
            'name' => 'Owner',
            'website' => 'https://example.com',
            'geometry' => ['location' => ['lat' => 53.0, 'lng' => 14.0]],
        ]);

        $toilet->refresh();

        $this->assertSame(52.0, $toilet->lat);
        $this->assertSame(13.0, $toilet->lon);

        $toilet->delete();
    }

    public function test_create_toilet_from_place_sets_public_accessible_for_matching_types(): void
    {
        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->method('crawlWebsite')
            ->willReturn([
                'toiletType' => 'forall',
                'contactEmail' => '',
                'resultCount' => 1,
            ]);

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => 'place_train_station_test',
            'displayName' => ['text' => 'Main Station'],
            'websiteUri' => 'https://station.example.com',
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['train_station', 'transit_station', 'point_of_interest'],
        ]);

        $this->assertNotNull($toilet);
        $property = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'public_accessible')
            ->first();

        $this->assertNotNull($property);
        $this->assertSame('1', $property->value);

        DB::table('toilet_properties')->where('fk_toiletId', $toilet->id)->delete();
        DB::table('type_x_place')->where('place_id', 'place_train_station_test')->delete();
        $toilet->delete();
    }

    public function test_create_toilet_from_place_does_not_set_public_accessible_for_non_public_types(): void
    {
        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->method('crawlWebsite')
            ->willReturn([
                'toiletType' => 'forall',
                'contactEmail' => '',
                'resultCount' => 1,
            ]);

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => 'place_restaurant_test',
            'displayName' => ['text' => 'Sample Restaurant'],
            'websiteUri' => 'https://restaurant.example.com',
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['restaurant', 'food', 'point_of_interest'],
        ]);

        $this->assertNotNull($toilet);
        $property = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'public_accessible')
            ->first();

        $this->assertNull($property);

        DB::table('toilet_properties')->where('fk_toiletId', $toilet->id)->delete();
        DB::table('type_x_place')->where('place_id', 'place_restaurant_test')->delete();
        $toilet->delete();
    }

    public function test_update_toilet_from_place_generates_revision_with_source_update_from_place(): void
    {
        $placeId = 'place_rev_test_'.uniqid();

        $toilet = Toilet::create([
            'name' => 'Original Name',
            'owner' => 'Original Owner',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        /** @var ToiletRevisionService $revisionService */
        $revisionService = app(ToiletRevisionService::class);
        $revisionService->recordRevision($toilet, 'initial');

        $placesService = $this->createMock(GooglePlacesService::class);
        $service = new PlaceToiletService($placesService);

        $changes = [];
        $result = $service->updateToiletFromPlace($toilet, [
            'place_id' => $placeId,
            'name' => 'Updated Place Owner',
            'location' => ['lat' => 52.5, 'lng' => 13.5],
            'formatted_address' => 'New Place Address 123',
            'types' => ['point_of_interest'],
        ], null, $changes);

        $this->assertTrue($result);
        $this->assertNotEmpty($changes);

        // Check revisions: 1 initial + 1 update-from-place
        $revisions = ToiletRevision::where('toilet_id', $toilet->id)->orderBy('version')->get();
        $this->assertCount(2, $revisions);

        $revision = $revisions[1];
        $this->assertSame('update-from-place', $revision->source);
        $this->assertSame(2, $revision->version);
        $this->assertStringContainsString($placeId, $revision->summary);
        $this->assertArrayHasKey('owner', $revision->diff);
        $this->assertSame(['old' => 'Original Owner', 'new' => 'Updated Place Owner'], $revision->diff['owner']);
        $this->assertArrayHasKey('lat', $revision->diff);
        $this->assertEquals(['old' => 52.0, 'new' => 52.5], $revision->diff['lat']);
        $this->assertArrayHasKey('lon', $revision->diff);
        $this->assertEquals(['old' => 13.0, 'new' => 13.5], $revision->diff['lon']);
        $this->assertArrayHasKey('address', $revision->diff);

        // Verify toilet version and last_diff
        $toilet->refresh();
        $this->assertSame(2, $toilet->version);
        $this->assertNotEmpty($toilet->last_diff);
    }

    public function test_update_toilet_from_place_does_not_generate_revision_when_no_changes(): void
    {
        $placeId = 'place_no_change_'.uniqid();

        $toilet = Toilet::create([
            'name' => 'Same Name',
            'owner' => 'Same Owner',
            'lat' => 52.5,
            'lon' => 13.5,
            'place_id' => $placeId,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet->id,
            'type' => 'address',
            'value' => 'Same Address',
            'user_overridden' => 0,
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $service = new PlaceToiletService($placesService);

        $changes = [];
        $result = $service->updateToiletFromPlace($toilet, [
            'place_id' => $placeId,
            'name' => 'Same Owner',
            'location' => ['lat' => 52.5, 'lng' => 13.5],
            'formatted_address' => 'Same Address',
            'types' => ['point_of_interest'],
        ], null, $changes);

        $this->assertFalse($result);
        $this->assertEmpty($changes);

        // No revision should be recorded
        $revisions = ToiletRevision::where('toilet_id', $toilet->id)->get();
        $this->assertCount(0, $revisions);

        $toilet->refresh();
        $this->assertSame(1, $toilet->version);
    }

    public function test_update_toilet_from_place_with_flags_set_and_null_last_crawled_does_not_throw(): void
    {
        $placeId = 'place_null_crawl_'.uniqid();

        $toilet = Toilet::create([
            'name' => 'WC With Flag',
            'owner' => 'Owner',
            'lat' => 52.5,
            'lon' => 13.5,
            'place_id' => $placeId,
            'status' => 'active',
            'last_crawled' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // Set type flag so $needsCrawl is false
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet->id,
            'type' => 'has_wheelchair_access',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $changes = [];
        $result = $service->updateToiletFromPlace($toilet, [
            'place_id' => $placeId,
            'name' => 'Owner',
            'website' => 'https://example.com',
            'location' => ['lat' => 52.5, 'lng' => 13.5],
            'formatted_address' => 'Test Address',
            'types' => ['point_of_interest'],
        ], null, $changes);

        $this->assertNull($toilet->last_crawled);
    }

    public function test_update_toilet_from_place_ignores_floating_point_truncation_inaccuracies(): void
    {
        $placeId = 'place_float_test_'.uniqid();

        $toilet = Toilet::create([
            'name' => 'WC Float Precision',
            'owner' => 'Owner',
            'lat' => 52.5200000,
            'lon' => 7.4091114,
            'place_id' => $placeId,
            'status' => 'active',
            'flagged' => 0,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // Set type flag so $needsCrawl is false and set website
        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $toilet->id, 'type' => 'is_unisex', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $toilet->id, 'type' => 'website', 'value' => 'https://example.com', 'user_overridden' => 0],
        ]);

        $placesService = $this->createMock(GooglePlacesService::class);
        $service = new PlaceToiletService($placesService);

        $changes = [];
        $result = $service->updateToiletFromPlace($toilet, [
            'place_id' => $placeId,
            'name' => 'Owner',
            'website' => 'https://example.com',
            'location' => [
                'lat' => 52.52000000000001,
                'lng' => 7.409111399999999,
            ],
            'formatted_address' => null,
            'types' => ['point_of_interest'],
        ], null, $changes);

        $this->assertFalse($result);
        $this->assertArrayNotHasKey('lat', $changes);
        $this->assertArrayNotHasKey('lon', $changes);
        $this->assertEmpty($changes);

        $toilet->refresh();
        $this->assertSame(52.52, $toilet->lat);
        $this->assertSame(7.4091114, $toilet->lon);
        $this->assertFalse((bool) $toilet->flagged);
    }

    public function test_create_toilet_from_place_ignores_deleted_toilets_with_same_place_id(): void
    {
        $placeId = 'place_deleted_test_'.uniqid();

        $deletedToilet = Toilet::create([
            'name' => 'Deleted Toilet',
            'owner' => 'Old Owner',
            'place_id' => $placeId,
            'status' => 'deleted',
        ]);
        $this->createdToiletIds[] = $deletedToilet->id;

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->method('crawlWebsite')
            ->willReturn([
                'toiletType' => 'forall',
                'contactEmail' => 'new@example.com',
                'resultCount' => 1,
            ]);

        $service = new PlaceToiletService($placesService);

        $newToilet = $service->createToiletFromPlace([
            'place_id' => $placeId,
            'name' => 'New Place Owner',
            'website' => 'https://newplace.example.com',
            'location' => ['lat' => 52.52, 'lng' => 13.40],
            'formatted_address' => 'Sample Address',
        ]);

        $this->assertNotNull($newToilet);
        $this->assertNotEquals($deletedToilet->id, $newToilet->id);
        $this->assertSame('active', $newToilet->status);
        $this->createdToiletIds[] = $newToilet->id;
    }

    public function test_create_toilet_from_public_bathroom_without_website_is_active_and_public(): void
    {
        $placeId = 'place_pb_no_web_'.uniqid();

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => $placeId,
            'displayName' => ['text' => 'Public Restroom Test'],
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['public_bathroom', 'point_of_interest'],
        ]);

        $this->assertNotNull($toilet);
        $this->createdToiletIds[] = $toilet->id;

        $this->assertSame('active', $toilet->status);
        $this->assertSame('auto_crawl_with_public_bathroom', $toilet->source);
        $this->assertSame('WC #'.$toilet->id, $toilet->name);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['public_accessible'] ?? null);
        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertArrayNotHasKey('has_wheelchair_access', $properties);
    }

    public function test_create_toilet_from_public_bathroom_with_wheelchair_access(): void
    {
        $placeId = 'place_pb_wheelchair_'.uniqid();

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => $placeId,
            'displayName' => ['text' => 'Accessible Public Toilet'],
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['public_bathroom'],
            'accessibilityOptions' => [
                'wheelchairAccessibleEntrance' => true,
            ],
        ]);

        $this->assertNotNull($toilet);
        $this->createdToiletIds[] = $toilet->id;

        $this->assertSame('active', $toilet->status);
        $this->assertSame('auto_crawl_with_public_bathroom', $toilet->source);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['public_accessible'] ?? null);
        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertSame('1', $properties['has_wheelchair_access'] ?? null);
    }

    public function test_create_toilet_from_public_bathroom_with_wheelchair_accessible_restroom(): void
    {
        $placeId = 'place_pb_restroom_'.uniqid();

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => $placeId,
            'displayName' => ['text' => 'Accessible Restroom Public Toilet'],
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['public_bathroom'],
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
        ]);

        $this->assertNotNull($toilet);
        $this->createdToiletIds[] = $toilet->id;

        $this->assertSame('active', $toilet->status);
        $this->assertSame('auto_crawl_with_public_bathroom', $toilet->source);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['public_accessible'] ?? null);
        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertSame('1', $properties['has_wheelchair_access'] ?? null);
    }

    public function test_create_toilet_from_place_with_restroom_true_creates_active_unisex_toilet_without_website(): void
    {
        $placeId = 'place_restroom_true_'.uniqid();

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => $placeId,
            'displayName' => ['text' => 'Cafe with Restroom'],
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['cafe'],
            'restroom' => true,
        ]);

        $this->assertNotNull($toilet);
        $this->createdToiletIds[] = $toilet->id;

        $this->assertSame('active', $toilet->status);
        $this->assertSame('WC #'.$toilet->id, $toilet->name);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertArrayNotHasKey('has_wheelchair_access', $properties);
    }

    public function test_create_toilet_from_place_with_restroom_and_wheelchair_accessible_restroom(): void
    {
        $placeId = 'place_restroom_wc_'.uniqid();

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => $placeId,
            'displayName' => ['text' => 'Accessible Restroom Cafe'],
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['cafe'],
            'restroom' => true,
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
        ]);

        $this->assertNotNull($toilet);
        $this->createdToiletIds[] = $toilet->id;

        $this->assertSame('active', $toilet->status);
        $this->assertSame('WC #'.$toilet->id, $toilet->name);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertSame('1', $properties['has_wheelchair_access'] ?? null);
    }

    public function test_create_toilet_from_place_with_only_wheelchair_accessible_restroom(): void
    {
        $placeId = 'place_only_wc_restroom_'.uniqid();

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $toilet = $service->createToiletFromPlace([
            'id' => $placeId,
            'displayName' => ['text' => 'Restaurant with WC Restroom'],
            'location' => ['latitude' => 52.52, 'longitude' => 13.40],
            'types' => ['restaurant'],
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
        ]);

        $this->assertNotNull($toilet);
        $this->createdToiletIds[] = $toilet->id;

        $this->assertSame('active', $toilet->status);
        $this->assertSame('WC #'.$toilet->id, $toilet->name);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertSame('1', $properties['has_wheelchair_access'] ?? null);
    }

    public function test_update_toilet_from_place_with_restroom_activates_previously_hidden_toilet(): void
    {
        $placeId = 'place_update_restroom_'.uniqid();

        $toilet = Toilet::create([
            'name' => 'Toilette',
            'owner' => 'Old Place',
            'lat' => 52.52,
            'lon' => 13.40,
            'place_id' => $placeId,
            'status' => 'hidden',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $placesService = $this->createMock(GooglePlacesService::class);
        $placesService->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesService);

        $changes = [];
        $service->updateToiletFromPlace($toilet, [
            'place_id' => $placeId,
            'restroom' => true,
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
        ], null, $changes);

        $toilet->refresh();

        $this->assertSame('active', $toilet->status);
        $this->assertSame('WC #'.$toilet->id, $toilet->name);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertSame('1', $properties['has_wheelchair_access'] ?? null);
    }
}
