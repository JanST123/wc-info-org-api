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
        $response->assertSee('Old Name');
        $response->assertSee('Wheelchair Accessible');
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
}
