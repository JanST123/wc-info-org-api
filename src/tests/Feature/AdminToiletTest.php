<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Models\ToiletProperty;
use App\Services\GooglePlacesService;
use App\Services\S3PhotoStorageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminToiletTest extends TestCase
{
    private array $createdToiletIds = [];
    private array $createdPlaceIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletPhoto::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_legacy_qualify_and_delete_endpoints_are_removed(): void
    {
        $toilet = Toilet::create([
            'name' => 'Legacy Endpoint Test Toilet',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $resQualify = $this->get('/toilet/' . $toilet->id . '/admin-qualify');
        $resQualify->assertStatus(404);

        $resDelete = $this->get('/toilet/' . $toilet->id . '/admin-delete');
        $resDelete->assertStatus(404);
    }

    public function test_dashboard_lists_toilets_added_in_last_24_hours(): void
    {
        // 1. Toilet created recently (e.g. now)
        $recentToilet = Toilet::create([
            'name' => 'Recent Admin Toilet 24h',
            'owner' => 'City Council',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'status' => 'active',
            'created_at' => Carbon::now()->subMinutes(10),
        ]);
        $this->createdToiletIds[] = $recentToilet->id;

        // 2. Toilet created 3 days ago
        $oldToilet = Toilet::create([
            'name' => 'Old Toilet 3 Days Ago',
            'status' => 'active',
            'created_at' => Carbon::now()->subDays(3),
        ]);
        $this->createdToiletIds[] = $oldToilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Recent Admin Toilet 24h');
        $response->assertSee((string) $recentToilet->id);
    }

    public function test_find_toilet_by_id(): void
    {
        $toilet = Toilet::create([
            'name' => 'Searchable Toilet',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // Existing toilet
        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/toilets/find', ['id' => $toilet->id]);

        $response->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));

        // Non-existing toilet
        $invalidResponse = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/toilets/find', ['id' => 99999999]);

        $invalidResponse->assertRedirect(route('admin.index'));
        $invalidResponse->assertSessionHas('error');
    }

    public function test_show_toilet_view_renders_correctly(): void
    {
        $place = Place::create([
            'place_id' => 'ChIJtestplace123',
            'data' => [
                'displayName' => ['text' => 'Alexanderplatz Station'],
                'formattedAddress' => 'Alexanderplatz, 10178 Berlin',
            ],
        ]);
        $this->createdPlaceIds[] = $place->place_id;

        $toilet = Toilet::create([
            'name' => 'Show View Test Toilet',
            'owner' => 'DB Station',
            'lat' => 52.5215,
            'lon' => 13.4112,
            'place_id' => 'ChIJtestplace123',
            'status' => 'active',
            'is_qualified' => 1,
            'last_diff' => json_encode(['name' => ['old' => 'Old Name', 'new' => 'Show View Test Toilet']]),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        ToiletProperty::create([
            'fk_toiletId' => $toilet->id,
            'type' => 'has_wheelchair_access',
            'value' => '1',
            'user_overridden' => 1,
        ]);

        ToiletProperty::create([
            'fk_toiletId' => $toilet->id,
            'type' => 'euro_key',
            'value' => 'yes',
            'user_overridden' => 1,
        ]);

        $response = $this->withSession(['admin_logged_in' => true])
            ->get('/admin/toilets/' . $toilet->id);

        $response->assertStatus(200);
        $response->assertSee('Show View Test Toilet');
        $response->assertSee('DB Station');
        $response->assertSee('ChIJtestplace123');
        $response->assertSee('Alexanderplatz Station');
        $response->assertDontSee('Current Place');
        $response->assertSee('Old Name');
        $response->assertSee('Wheelchair Accessible');
        $response->assertSee('Open in Google Maps');
        $response->assertSee('https://www.google.com/maps/search/?api=1&amp;query=52.5215,13.4112', false);
    }

    public function test_update_toilet_details_and_properties(): void
    {
        $toilet = Toilet::create([
            'name' => 'Original Name',
            'owner' => 'Original Owner',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'status' => 'hidden',
            'is_qualified' => 0,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $updateData = [
            'name' => 'Updated Admin Toilet',
            'owner' => 'Updated City Authority',
            'lat' => 52.5300,
            'lon' => 13.4150,
            'place_id' => 'ChIJnewplace456',
            'status' => 'active',
            'is_qualified' => '1',
            'contact_email' => 'admin@example.com',
            'source' => 'admin_panel',
            'is_unisex' => '1',
            'is_gender_separated' => '0',
            'has_wheelchair_access' => '1',
            'has_changing_table' => '1',
            'accessible_outside_opening_times' => '1',
            'public_accessible' => '1',
            'euro_key' => 'yes',
            'storage_space' => 'much',
            'address' => 'Sample Street 123',
            'website' => 'https://example.com/toilet',
            'comment' => 'Clean and accessible',
            'place_opening_hours' => json_encode([
                ['open' => ['day' => 1, 'hour' => 8, 'minute' => 0], 'close' => ['day' => 1, 'hour' => 20, 'minute' => 0]],
            ]),
            'custom_property_type' => ['comment'],
            'custom_property_value' => ['Updated comment via dynamic row'],
        ];

        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/toilets/' . $toilet->id, $updateData);

        $response->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));
        $response->assertSessionHas('success');

        $toilet->refresh();
        $this->assertSame('Updated Admin Toilet', $toilet->name);
        $this->assertSame('Updated City Authority', $toilet->owner);
        $this->assertEquals(52.5300, $toilet->lat);
        $this->assertEquals(13.4150, $toilet->lon);
        $this->assertSame('ChIJnewplace456', $toilet->place_id);
        $this->assertSame('active', $toilet->status);
        $this->assertTrue((bool) $toilet->is_qualified);
        $this->assertSame('admin@example.com', $toilet->contact_email);
        $this->assertSame('admin_panel', $toilet->source);

        // Verify properties
        $this->assertEquals('1', $toilet->propertyValue('is_unisex'));
        $this->assertEquals('1', $toilet->propertyValue('has_wheelchair_access'));
        $this->assertEquals('1', $toilet->propertyValue('has_changing_table'));
        $this->assertEquals('1', $toilet->propertyValue('accessible_outside_opening_times'));
        $this->assertEquals('1', $toilet->propertyValue('public_accessible'));
        $this->assertEquals('yes', $toilet->propertyValue('euro_key'));
        $this->assertEquals('much', $toilet->propertyValue('storage_space'));
        $this->assertEquals('Sample Street 123', $toilet->propertyValue('address'));
        $this->assertEquals('https://example.com/toilet', $toilet->propertyValue('website'));
        $this->assertEquals('Updated comment via dynamic row', $toilet->propertyValue('comment'));

        // Verify diff recorded
        $this->assertNotEmpty($toilet->last_diff);
        $diff = json_decode((string) $toilet->last_diff, true);
        $this->assertArrayHasKey('name', $diff);
        $this->assertSame('Original Name', $diff['name']['old']);
        $this->assertSame('Updated Admin Toilet', $diff['name']['new']);
    }

    public function test_reschedule_discovery_sets_last_included_to_now(): void
    {
        $toilet = Toilet::create([
            'name' => 'Reschedule Test Toilet',
            'status' => 'active',
            'last_included' => Carbon::now()->subDays(10),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/toilets/' . $toilet->id . '/reschedule-discovery');

        $response->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));
        $response->assertSessionHas('success');

        $toilet->refresh();
        $this->assertNotNull($toilet->last_included);
        $this->assertTrue($toilet->last_included->isToday());
    }

    public function test_delete_photo_soft_and_hard(): void
    {
        $toilet = Toilet::create([
            'name' => 'Photo Test Toilet',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $photo1 = ToiletPhoto::create([
            'fk_toiletId' => $toilet->id,
            'filename' => 'test_photo_1.jpg',
            'filename_thumb' => 'test_photo_1.thumb.jpg',
        ]);

        $photo2 = ToiletPhoto::create([
            'fk_toiletId' => $toilet->id,
            'filename' => 'test_photo_2.jpg',
            'filename_thumb' => 'test_photo_2.thumb.jpg',
        ]);

        // Mock S3
        $s3Mock = $this->createMock(S3PhotoStorageService::class);
        $s3Mock->method('exists')->willReturn(true);
        $s3Mock->expects($this->any())->method('rename');
        $s3Mock->expects($this->any())->method('delete');
        $this->app->instance(S3PhotoStorageService::class, $s3Mock);

        // 1. Soft delete photo 1
        $resSoft = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/toilets/' . $toilet->id . '/photos/' . $photo1->filename . '/delete');

        $resSoft->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));
        $photo1->refresh();
        $this->assertNotNull($photo1->deleted_ts);
        $this->assertStringStartsWith('_DELETED_', $photo1->filename);

        // 2. Hard delete photo 2
        $resHard = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/toilets/' . $toilet->id . '/photos/' . $photo2->filename . '/delete', ['hard' => 1]);

        $resHard->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));
        $this->assertNull(ToiletPhoto::find($photo2->id));
    }

    public function test_update_toilet_with_use_place_coordinates_sets_lat_lon_and_removes_override(): void
    {
        $place = Place::create([
            'place_id' => 'ChIJplacecoords789',
            'data' => [
                'displayName' => ['text' => 'Coordinates Place'],
                'formattedAddress' => 'Places Street 10, Berlin',
                'location' => [
                    'latitude' => 52.5412,
                    'longitude' => 13.4412,
                ],
            ],
        ]);
        $this->createdPlaceIds[] = $place->place_id;

        $toilet = Toilet::create([
            'name' => 'Overridden Toilet',
            'lat' => 52.5100,
            'lon' => 13.4100,
            'place_id' => 'ChIJplacecoords789',
            'status' => 'active',
            'user_overridden' => ['lat', 'lon', 'name'],
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $this->assertTrue($toilet->isUserOverridden('lat'));
        $this->assertTrue($toilet->isUserOverridden('lon'));
        $this->assertTrue($toilet->isUserOverridden('name'));

        $updateData = [
            'name' => 'Overridden Toilet',
            'place_id' => 'ChIJplacecoords789',
            'use_place_coordinates' => '1',
            'status' => 'active',
        ];

        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/toilets/' . $toilet->id, $updateData);

        $response->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));
        $response->assertSessionHas('success');

        $toilet->refresh();
        $this->assertEquals(52.5412, $toilet->lat);
        $this->assertEquals(13.4412, $toilet->lon);
        $this->assertFalse($toilet->isUserOverridden('lat'));
        $this->assertFalse($toilet->isUserOverridden('lon'));
        $this->assertTrue($toilet->isUserOverridden('name'));
    }

    public function test_flagged_toilets_are_displayed_in_dashboard_with_properties(): void
    {
        $place = Place::create([
            'place_id' => 'ChIJflaggedplace123',
            'data' => [
                'displayName' => ['text' => 'Flagged Cafe Place'],
                'formattedAddress' => 'Flagged Street 1, Berlin',
            ],
        ]);
        $this->createdPlaceIds[] = $place->place_id;

        $toilet = Toilet::create([
            'name' => 'Flagged Toilet Item',
            'owner' => 'Flagged Owner',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'place_id' => 'ChIJflaggedplace123',
            'status' => 'active',
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        ToiletProperty::create([
            'fk_toiletId' => $toilet->id,
            'type' => 'comment',
            'value' => 'Needs place re-verification',
        ]);
        ToiletProperty::create([
            'fk_toiletId' => $toilet->id,
            'type' => 'address',
            'value' => 'Alexanderplatz 5, 10178 Berlin',
        ]);

        $response = $this->withSession(['admin_logged_in' => true])
            ->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Flagged Toilet Item');
        $response->assertSee('Needs place re-verification');
        $response->assertSee('Alexanderplatz 5, 10178 Berlin');
        $response->assertSee('Flagged Cafe Place');
        $response->assertSee('https://www.google.com/maps/search/?api=1&query=52.52,13.405', false);
    }

    public function test_get_nearby_places_returns_places_json(): void
    {
        $toilet = Toilet::create([
            'name' => 'Nearby Places Toilet',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'status' => 'active',
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // Mock GoogleCostService to ensure budget is available
        $costMock = $this->createMock(\App\Services\GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);
        $this->app->instance(\App\Services\GoogleCostService::class, $costMock);

        // Mock GooglePlacesService
        $placesMock = $this->createMock(GooglePlacesService::class);
        $placesMock->expects($this->once())
            ->method('nearbySearchRaw')
            ->with(52.5200, 13.4050, 0.04)
            ->willReturn([
                [
                    'id' => 'ChIJnewplace456',
                    'displayName' => ['text' => 'Nearby Bakery'],
                    'formattedAddress' => 'Nearby Str. 2',
                    'location' => ['latitude' => 52.5201, 'longitude' => 13.4051],
                ],
            ]);
        $this->app->instance(GooglePlacesService::class, $placesMock);

        $response = $this->withSession(['admin_logged_in' => true])
            ->getJson("/admin/toilets/{$toilet->id}/nearby-places");

        $response->assertStatus(200);
        $response->assertJsonStructure(['places' => [['place_id', 'name', 'address', 'distance_m']]]);
        $response->assertJsonFragment([
            'place_id' => 'ChIJnewplace456',
            'name' => 'Nearby Bakery',
        ]);

        // Verify place was saved in places table
        $this->assertNotNull(Place::find('ChIJnewplace456'));
        $this->createdPlaceIds[] = 'ChIJnewplace456';
    }

    public function test_get_nearby_places_returns_429_when_budget_exceeded(): void
    {
        $toilet = Toilet::create([
            'name' => 'Nearby Places Toilet Budget Exceeded',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'status' => 'active',
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $costMock = $this->createMock(\App\Services\GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(false);
        $this->app->instance(\App\Services\GoogleCostService::class, $costMock);

        $response = $this->withSession(['admin_logged_in' => true])
            ->getJson("/admin/toilets/{$toilet->id}/nearby-places");

        $response->assertStatus(429);
        $response->assertJson([
            'error' => 'Monthly Google Cloud API budget exceeded',
            'places' => [],
        ]);
    }

    public function test_assign_place_updates_toilet(): void
    {
        $place = Place::create([
            'place_id' => 'ChIJassignedplace999',
            'data' => [
                'displayName' => ['text' => 'Assigned Place'],
            ],
        ]);
        $this->createdPlaceIds[] = $place->place_id;

        $toilet = Toilet::create([
            'name' => 'Assign Place Toilet',
            'status' => 'active',
            'place_id' => null,
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->postJson("/admin/toilets/{$toilet->id}/assign-place", [
                'place_id' => 'ChIJassignedplace999',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'toilet_id' => $toilet->id,
            'place_id' => 'ChIJassignedplace999',
            'place_name' => 'Assigned Place',
        ]);

        $toilet->refresh();
        $this->assertEquals('ChIJassignedplace999', $toilet->place_id);
        $this->assertTrue($toilet->isUserOverridden('place_id'));
    }

    public function test_unflag_sets_flagged_to_false(): void
    {
        $toilet = Toilet::create([
            'name' => 'Toilet to Unflag',
            'status' => 'active',
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->postJson("/admin/toilets/{$toilet->id}/unflag");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'toilet_id' => $toilet->id,
            'flagged' => false,
        ]);

        $toilet->refresh();
        $this->assertFalse((bool) $toilet->flagged);
    }

    public function test_toggle_flag(): void
    {
        $toilet = Toilet::create([
            'name' => 'Toggle Flag Toilet',
            'status' => 'active',
            'flagged' => 0,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->postJson("/admin/toilets/{$toilet->id}/toggle-flag");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'toilet_id' => $toilet->id,
            'flagged' => true,
        ]);

        $toilet->refresh();
        $this->assertTrue((bool) $toilet->flagged);
    }

    public function test_ai_suggest_place_endpoint(): void
    {
        $toilet = Toilet::create([
            'name' => 'Toilet for AI Test',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'status' => 'active',
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $aiMock = $this->createMock(\App\Services\GeminiPlaceMatchingService::class);
        $aiMock->expects($this->once())
            ->method('suggestPlace')
            ->willReturn([
                'success' => true,
                'matched' => true,
                'toilet_id' => $toilet->id,
                'toilet_name' => $toilet->name,
                'toilet_lat' => 52.5200,
                'toilet_lon' => 13.4050,
                'place' => [
                    'place_id' => 'ChIJaiplace123',
                    'name' => 'AI Matched Train Station',
                    'address' => 'Station Square 1',
                    'types' => ['train_station', 'transit_station'],
                    'lat' => 52.5201,
                    'lon' => 13.4051,
                    'distance_m' => 15,
                    'maps_url' => 'https://www.google.com/maps/search/?api=1&query=52.52,13.405&query_place_id=ChIJaiplace123',
                    'is_public_accessible' => true,
                ],
                'confidence' => 'high',
                'reasoning' => 'Matched with train station at the same coordinate.',
                'source' => 'nearby_gemini',
            ]);
        $this->app->instance(\App\Services\GeminiPlaceMatchingService::class, $aiMock);

        $response = $this->withSession(['admin_logged_in' => true])
            ->getJson("/admin/toilets/{$toilet->id}/ai-suggest-place");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'matched' => true,
            'place' => [
                'place_id' => 'ChIJaiplace123',
                'name' => 'AI Matched Train Station',
                'is_public_accessible' => true,
            ],
            'confidence' => 'high',
        ]);
    }

    public function test_ai_accept_place_updates_place_and_sets_public_accessible(): void
    {
        $place = Place::create([
            'place_id' => 'ChIJacceptedAiPlace777',
            'data' => [
                'displayName' => ['text' => 'Accepted Station'],
                'location' => [
                    'latitude' => 52.5205,
                    'longitude' => 13.4055,
                ],
            ],
        ]);
        $this->createdPlaceIds[] = $place->place_id;

        $toilet = Toilet::create([
            'name' => 'Toilet for AI Accept',
            'status' => 'active',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'place_id' => null,
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->postJson("/admin/toilets/{$toilet->id}/ai-accept-place", [
                'place_id' => 'ChIJacceptedAiPlace777',
                'set_public_accessible' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'toilet_id' => $toilet->id,
            'place_id' => 'ChIJacceptedAiPlace777',
            'lat' => 52.5205,
            'lon' => 13.4055,
            'public_accessible' => true,
        ]);

        $toilet->refresh();
        $this->assertEquals('ChIJacceptedAiPlace777', $toilet->place_id);
        $this->assertEquals(52.5205, $toilet->lat);
        $this->assertEquals(13.4055, $toilet->lon);
        $this->assertTrue($toilet->isUserOverridden('place_id'));
        $this->assertTrue($toilet->isUserOverridden('lat'));
        $this->assertTrue($toilet->isUserOverridden('lon'));
        $this->assertEquals('1', $toilet->propertyValue('public_accessible'));
    }

    public function test_quick_update_status_endpoint(): void
    {
        $toilet = Toilet::create([
            'name' => 'Toilet for Status Quick Change',
            'status' => 'active',
            'flagged' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->postJson("/admin/toilets/{$toilet->id}/status", [
                'status' => 'hidden',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'toilet_id' => $toilet->id,
            'status' => 'hidden',
        ]);

        $toilet->refresh();
        $this->assertEquals('hidden', $toilet->status);
        $this->assertTrue($toilet->isUserOverridden('status'));
    }
}

