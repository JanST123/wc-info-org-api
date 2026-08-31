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
}
