<?php

namespace Tests\Unit;

use App\Models\Toilet;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlaceToiletServiceTest extends TestCase
{
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
}
