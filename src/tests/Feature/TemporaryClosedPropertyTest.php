<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Place;
use App\Models\Toilet;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemporaryClosedPropertyTest extends TestCase
{
    private string $apiKey = 'test-api-key-temporary-closed';

    private array $createdToiletIds = [];

    private array $createdPlaceIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        ApiKey::updateOrCreate(
            ['key' => $this->apiKey],
            [
                'name' => 'Test Temporary Closed Key',
                'is_active' => true,
                'rate_limit_per_minute' => 100,
                'block_duration_seconds' => 120,
                'slowdown_duration_seconds' => 300,
                'slowdown_rate_limit' => 20,
                'global_limit_per_minute' => 1000,
            ]
        );
    }

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            DB::table('toilet_revisions')->whereIn('toilet_id', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            DB::table('type_x_place')->whereIn('place_id', $this->createdPlaceIds)->delete();
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        ApiKey::where('key', $this->apiKey)->delete();

        parent::tearDown();
    }

    public function test_get_toilet_detail_returns_temporary_closed_and_marks_is_open_false(): void
    {
        $toilet = Toilet::create([
            'name' => 'Temp Closed Toilet',
            'status' => 'active',
            'lat' => 52.52,
            'lon' => 13.40,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $toilet->id, 'type' => 'temporary_closed', 'value' => '1', 'user_overridden' => 0],
        ]);

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson("/toilet/{$toilet->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $toilet->id,
            'flags' => [
                'temporary_closed' => true,
            ],
            'is_open' => false,
            'open_timestamp' => null,
            'close_timestamp' => null,
        ]);
    }

    public function test_nearby_and_bounds_filter_respects_temporary_closed(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $currentDay = (int) $now->format('w');
        $currentHour = (int) $now->format('H');
        $openHour = ($currentHour - 1 + 24) % 24;
        $closeHour = ($currentHour + 2) % 24;

        // Toilet 1: Open by hours, but temporarily closed
        $toilet1 = Toilet::create([
            'name' => 'Toilet Closed Temp',
            'status' => 'active',
            'lat' => 52.5201,
            'lon' => 13.4001,
        ]);
        $this->createdToiletIds[] = $toilet1->id;
        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $toilet1->id, 'type' => 'temporary_closed', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $toilet1->id, 'type' => 'place_opening_hours', 'value' => json_encode([
                ['open' => ['day' => $currentDay, 'hour' => $openHour, 'minute' => 0], 'close' => ['day' => $currentDay, 'hour' => $closeHour, 'minute' => 0]],
            ]), 'user_overridden' => 0],
        ]);

        // Toilet 2: Open and not temporarily closed
        $toilet2 = Toilet::create([
            'name' => 'Toilet Open Normal',
            'status' => 'active',
            'lat' => 52.5202,
            'lon' => 13.4002,
        ]);
        $this->createdToiletIds[] = $toilet2->id;
        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $toilet2->id, 'type' => 'place_opening_hours', 'value' => json_encode([
                ['open' => ['day' => $currentDay, 'hour' => $openHour, 'minute' => 0], 'close' => ['day' => $currentDay, 'hour' => $closeHour, 'minute' => 0]],
            ]), 'user_overridden' => 0],
        ]);

        // Query with is_open:1 -> should only return toilet2
        $responseOpen = $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson('/toilets/bounds/52.51/13.39/52.53/13.41?filter=is_open:1');

        $responseOpen->assertStatus(200);
        $idsOpen = collect($responseOpen->json())->pluck('id')->all();
        $this->assertNotContains($toilet1->id, $idsOpen);
        $this->assertContains($toilet2->id, $idsOpen);

        // Query with temporary_closed:1 -> should only return toilet1
        $responseClosed = $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson('/toilets/bounds/52.51/13.39/52.53/13.41?filter=temporary_closed:1');

        $responseClosed->assertStatus(200);
        $idsClosed = collect($responseClosed->json())->pluck('id')->all();
        $this->assertContains($toilet1->id, $idsClosed);
        $this->assertNotContains($toilet2->id, $idsClosed);
    }

    public function test_post_toilet_add_and_patch_toilet_update_handles_temporary_closed(): void
    {
        // 1. Create toilet via POST /toilet/add
        $addResponse = $this->withHeader('X-Api-Key', $this->apiKey)
            ->postJson('/toilet/add', [
                'name' => 'New API Toilet',
                'lat' => 52.5205,
                'lon' => 13.4005,
                'temporary_closed' => true,
            ]);

        $addResponse->assertStatus(200);
        $toiletId = $addResponse->json('id');
        $this->createdToiletIds[] = $toiletId;

        $prop = DB::table('toilet_properties')
            ->where('fk_toiletId', $toiletId)
            ->where('type', 'temporary_closed')
            ->first();

        $this->assertNotNull($prop);
        $this->assertSame('1', $prop->value);
        $this->assertSame(1, (int) $prop->user_overridden);

        // 2. Update toilet via PATCH /toilet/{id}/update
        $updateResponse = $this->withHeader('X-Api-Key', $this->apiKey)
            ->patchJson("/toilet/{$toiletId}/update", [
                'temporary_closed' => false,
            ]);

        $updateResponse->assertStatus(200);
        $propUpdated = DB::table('toilet_properties')
            ->where('fk_toiletId', $toiletId)
            ->where('type', 'temporary_closed')
            ->first();

        $this->assertNotNull($propUpdated);
        $this->assertSame('0', $propUpdated->value);
        $this->assertSame(1, (int) $propUpdated->user_overridden);
    }

    public function test_place_toilet_service_sets_and_clears_temporary_closed_from_place_business_status(): void
    {
        $mockGoogle = $this->createMock(GooglePlacesService::class);
        $service = new PlaceToiletService($mockGoogle);

        $placeId = 'place_temp_closed_test_123';
        $this->createdPlaceIds[] = $placeId;

        $toilet = Toilet::create([
            'name' => 'Auto Toilet',
            'place_id' => $placeId,
            'status' => 'active',
            'lat' => 52.52,
            'lon' => 13.40,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // 1. Update from place with CLOSED_TEMPORARILY
        $changes = [];
        $placeData = [
            'place_id' => $placeId,
            'business_status' => 'CLOSED_TEMPORARILY',
        ];
        $service->updateToiletFromPlace($toilet, $placeData, null, $changes);

        $this->assertDatabaseHas('toilet_properties', [
            'fk_toiletId' => $toilet->id,
            'type' => 'temporary_closed',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        // 2. Update from place with OPERATIONAL -> should clear temporary_closed
        $changes2 = [];
        $placeData2 = [
            'place_id' => $placeId,
            'business_status' => 'OPERATIONAL',
        ];
        $service->updateToiletFromPlace($toilet, $placeData2, null, $changes2);

        $this->assertDatabaseMissing('toilet_properties', [
            'fk_toiletId' => $toilet->id,
            'type' => 'temporary_closed',
        ]);

        // 3. User manually overrides temporary_closed = 0
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet->id,
            'type' => 'temporary_closed',
            'value' => '0',
            'user_overridden' => 1,
        ]);

        // 4. Update from place with CLOSED_TEMPORARILY -> should NOT overwrite user override
        $changes3 = [];
        $service->updateToiletFromPlace($toilet, $placeData, null, $changes3);

        $prop = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'temporary_closed')
            ->first();

        $this->assertSame('0', $prop->value);
        $this->assertSame(1, (int) $prop->user_overridden);
    }

    public function test_admin_panel_can_edit_temporary_closed(): void
    {
        $toilet = Toilet::create([
            'name' => 'Admin Temp Closed Toilet',
            'status' => 'active',
            'lat' => 52.52,
            'lon' => 13.40,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->withSession(['admin_logged_in' => true])
            ->post("/admin/toilets/{$toilet->id}", [
                'name' => 'Admin Temp Closed Toilet',
                'status' => 'active',
                'temporary_closed' => '1',
            ]);

        $response->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));

        $prop = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->where('type', 'temporary_closed')
            ->first();

        $this->assertNotNull($prop);
        $this->assertSame('1', $prop->value);
        $this->assertSame(1, (int) $prop->user_overridden);
    }
}
