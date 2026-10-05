<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletProperty;
use App\Models\ToiletRevision;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlaceStorePublicBathroomTest extends TestCase
{
    private array $createdToiletIds = [];

    private array $createdPlaceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        ApiKey::updateOrCreate(
            ['key' => 'wc_test_key_abc123'],
            ['name' => 'Test Key', 'is_active' => true, 'rate_limit_per_minute' => 1000]
        );
    }

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
            DB::table('type_x_place')->whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_store_place_with_public_bathroom_creates_active_toilet_and_public_accessible(): void
    {
        $placeId = 'place_pb_store_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $response = $this->withHeaders(['X-Api-Key' => 'wc_test_key_abc123'])->postJson("/places/{$placeId}", [
            'id' => $placeId,
            'displayName' => ['text' => 'Public Restroom Alexanderplatz'],
            'location' => ['latitude' => 52.5219, 'longitude' => 13.4132],
            'formattedAddress' => 'Alexanderplatz 1, 10178 Berlin',
            'types' => ['public_bathroom', 'point_of_interest'],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'okay',
                'fetchedPlace' => true,
            ]);

        $newToiletId = $response->json('newToiletId');
        $this->assertNotNull($newToiletId);
        $this->createdToiletIds[] = $newToiletId;

        $toilet = Toilet::find($newToiletId);
        $this->assertNotNull($toilet);
        $this->assertSame('active', $toilet->status);
        $this->assertSame('auto_crawl_with_public_bathroom', $toilet->source);
        $this->assertSame('WC #'.$toilet->id, $toilet->name);
        $this->assertEquals(52.5219, $toilet->lat);
        $this->assertEquals(13.4132, $toilet->lon);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['public_accessible'] ?? null);
        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertArrayNotHasKey('has_wheelchair_access', $properties);
        $this->assertSame('Alexanderplatz 1, 10178 Berlin', $properties['address'] ?? null);
    }

    public function test_store_place_with_public_bathroom_and_wheelchair_accessibility(): void
    {
        $placeId = 'place_pb_wheelchair_store_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $response = $this->withHeaders(['X-Api-Key' => 'wc_test_key_abc123'])->postJson("/places/{$placeId}", [
            'id' => $placeId,
            'displayName' => ['text' => 'Accessible Restroom Hbf'],
            'location' => ['latitude' => 52.5255, 'longitude' => 13.3695],
            'types' => ['public_bathroom'],
            'accessibilityOptions' => [
                'wheelchairAccessibleEntrance' => true,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'okay',
                'fetchedPlace' => true,
            ]);

        $newToiletId = $response->json('newToiletId');
        $this->assertNotNull($newToiletId);
        $this->createdToiletIds[] = $newToiletId;

        $toilet = Toilet::find($newToiletId);
        $this->assertNotNull($toilet);
        $this->assertSame('active', $toilet->status);
        $this->assertSame('auto_crawl_with_public_bathroom', $toilet->source);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['public_accessible'] ?? null);
        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertSame('1', $properties['has_wheelchair_access'] ?? null);
    }

    public function test_store_place_with_public_bathroom_and_wheelchair_accessible_restroom(): void
    {
        $placeId = 'place_pb_wc_restroom_store_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $response = $this->withHeaders(['X-Api-Key' => 'wc_test_key_abc123'])->postJson("/places/{$placeId}", [
            'id' => $placeId,
            'displayName' => ['text' => 'Accessible Restroom Zoo'],
            'location' => ['latitude' => 52.5080, 'longitude' => 13.3320],
            'types' => ['public_bathroom'],
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'okay',
                'fetchedPlace' => true,
            ]);

        $newToiletId = $response->json('newToiletId');
        $this->assertNotNull($newToiletId);
        $this->createdToiletIds[] = $newToiletId;

        $toilet = Toilet::find($newToiletId);
        $this->assertNotNull($toilet);
        $this->assertSame('active', $toilet->status);
        $this->assertSame('auto_crawl_with_public_bathroom', $toilet->source);

        $properties = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type');

        $this->assertSame('1', $properties['public_accessible'] ?? null);
        $this->assertSame('1', $properties['is_unisex'] ?? null);
        $this->assertSame('1', $properties['has_wheelchair_access'] ?? null);
    }

    public function test_store_place_with_non_public_type_and_no_website_creates_hidden_toilet(): void
    {
        $placeId = 'place_hidden_store_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        $response = $this->withHeaders(['X-Api-Key' => 'wc_test_key_abc123'])->postJson("/places/{$placeId}", [
            'id' => $placeId,
            'displayName' => ['text' => 'Local Dentist Office'],
            'location' => ['latitude' => 52.5200, 'longitude' => 13.4000],
            'types' => ['dentist', 'health'],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'okay',
                'fetchedPlace' => true,
                'newToiletId' => null,
            ]);

        $hiddenToilet = Toilet::where('place_id', $placeId)->first();
        $this->assertNotNull($hiddenToilet);
        $this->createdToiletIds[] = $hiddenToilet->id;
        $this->assertSame('hidden', $hiddenToilet->status);
        $this->assertSame('auto_crawl', $hiddenToilet->source);
    }
}
