<?php

namespace Tests\Feature;

use App\Models\Toilet;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ToiletFilterTest extends TestCase
{
    private array $createdToiletIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        parent::tearDown();
    }

    public function test_bounds_filter_is_open_includes_open_accessible_outside_and_no_hours(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $currentDay = (int) $now->format('w');
        $currentHour = (int) $now->format('H');

        $openHour = ($currentHour - 1 + 24) % 24;
        $closeHour = ($currentHour + 2) % 24;
        $futureOpenHour = ($currentHour + 3) % 24;
        $futureCloseHour = ($currentHour + 5) % 24;

        // 1. Open toilet according to periods
        $openToilet = Toilet::create([
            'name' => 'Open Toilet',
            'owner' => 'Cafe Open',
            'lat' => 52.5,
            'lon' => 13.5,
            'place_id' => 'place_open',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $openToilet->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $openToilet->id,
            'type' => 'place_opening_hours',
            'value' => json_encode([
                ['open' => ['day' => $currentDay, 'hour' => $openHour, 'minute' => 0], 'close' => ['day' => $currentDay, 'hour' => $closeHour, 'minute' => 0]],
            ]),
        ]);

        // 2. Closed toilet (no outside opening hours access) -> should be EXCLUDED
        $closedToilet = Toilet::create([
            'name' => 'Closed Toilet',
            'owner' => 'Cafe Closed',
            'lat' => 52.5,
            'lon' => 13.5,
            'place_id' => 'place_closed',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $closedToilet->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $closedToilet->id,
            'type' => 'place_opening_hours',
            'value' => json_encode([
                ['open' => ['day' => $currentDay, 'hour' => $futureOpenHour, 'minute' => 0], 'close' => ['day' => $currentDay, 'hour' => $futureCloseHour, 'minute' => 0]],
            ]),
        ]);

        // 3. Closed according to periods BUT accessible_outside_opening_times -> should be INCLUDED
        $accessibleOutsideToilet = Toilet::create([
            'name' => 'Accessible Outside Hours Toilet',
            'owner' => 'Park Toilet',
            'lat' => 52.5,
            'lon' => 13.5,
            'place_id' => 'place_accessible_outside',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $accessibleOutsideToilet->id;
        DB::table('toilet_properties')->insert([
            [
                'fk_toiletId' => $accessibleOutsideToilet->id,
                'type' => 'place_opening_hours',
                'value' => json_encode([
                    ['open' => ['day' => $currentDay, 'hour' => $futureOpenHour, 'minute' => 0], 'close' => ['day' => $currentDay, 'hour' => $futureCloseHour, 'minute' => 0]],
                ]),
                'user_overridden' => 0,
            ],
            [
                'fk_toiletId' => $accessibleOutsideToilet->id,
                'type' => 'accessible_outside_opening_times',
                'value' => '1',
                'user_overridden' => 1,
            ],
        ]);

        // 4. Toilet with no opening times at all -> should be INCLUDED
        $noHoursToilet = Toilet::create([
            'name' => 'No Hours Toilet',
            'owner' => 'Street Toilet',
            'lat' => 52.5,
            'lon' => 13.5,
            'place_id' => 'place_no_hours',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $noHoursToilet->id;

        $response = $this->getJson('/toilets/bounds/52.0/13.0/53.0/14.0?filter=is_open:true');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($openToilet->id, $ids);
        $this->assertContains($accessibleOutsideToilet->id, $ids);
        $this->assertContains($noHoursToilet->id, $ids);
        $this->assertNotContains($closedToilet->id, $ids);
    }

    public function test_multiple_attribute_filters_all_must_match(): void
    {
        // Toilet 1: Matches all requested filters
        $perfectToilet = Toilet::create([
            'name' => 'Perfect Match Toilet',
            'lat' => 52.51,
            'lon' => 13.51,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $perfectToilet->id;
        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $perfectToilet->id, 'type' => 'public_accessible', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $perfectToilet->id, 'type' => 'has_wheelchair_access', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $perfectToilet->id, 'type' => 'has_changing_table', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $perfectToilet->id, 'type' => 'is_gender_separated', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $perfectToilet->id, 'type' => 'euro_key', 'value' => 'yes', 'user_overridden' => 0],
        ]);

        // Toilet 2: Missing euro_key (has 'no')
        $missingEuroKey = Toilet::create([
            'name' => 'Missing Euro Key Toilet',
            'lat' => 52.52,
            'lon' => 13.52,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $missingEuroKey->id;
        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $missingEuroKey->id, 'type' => 'public_accessible', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $missingEuroKey->id, 'type' => 'has_wheelchair_access', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $missingEuroKey->id, 'type' => 'has_changing_table', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $missingEuroKey->id, 'type' => 'is_gender_separated', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $missingEuroKey->id, 'type' => 'euro_key', 'value' => 'no', 'user_overridden' => 0],
        ]);

        // Toilet 3: Missing has_changing_table
        $missingChangingTable = Toilet::create([
            'name' => 'Missing Changing Table Toilet',
            'lat' => 52.53,
            'lon' => 13.53,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $missingChangingTable->id;
        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $missingChangingTable->id, 'type' => 'public_accessible', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $missingChangingTable->id, 'type' => 'has_wheelchair_access', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $missingChangingTable->id, 'type' => 'is_gender_separated', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $missingChangingTable->id, 'type' => 'euro_key', 'value' => 'yes', 'user_overridden' => 0],
        ]);

        // Test combined filter on bounds
        $filterQuery = 'public_accessible:true,has_wheelchair_access:true,has_changing_table:true,is_gender_separated:true,euro_key:yes';
        $response = $this->getJson("/toilets/bounds/52.0/13.0/53.0/14.0?filter={$filterQuery}");

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($perfectToilet->id, $ids);
        $this->assertNotContains($missingEuroKey->id, $ids);
        $this->assertNotContains($missingChangingTable->id, $ids);

        // Test nearby endpoint as well
        $nearbyResponse = $this->getJson("/toilets/nearby/52.51/13.51?distance=10&filter={$filterQuery}");
        $nearbyResponse->assertStatus(200);
        $nearbyIds = collect($nearbyResponse->json())->pluck('id')->all();
        $this->assertContains($perfectToilet->id, $nearbyIds);
        $this->assertNotContains($missingEuroKey->id, $nearbyIds);
        $this->assertNotContains($missingChangingTable->id, $nearbyIds);
    }

    public function test_bounds_discovers_toilets_when_database_is_empty(): void
    {
        $mockPlacesService = $this->createMock(\App\Services\GooglePlacesService::class);

        $discoveredToilet = new Toilet([
            'name' => 'Discovered Bounds Toilet',
            'lat' => 10.5,
            'lon' => 20.5,
            'status' => 'active',
        ]);
        $discoveredToilet->id = 999999;

        $mockPlacesService->expects($this->once())
            ->method('discoverToiletsNearby')
            ->with(
                $this->equalTo(10.5),
                $this->equalTo(20.5),
                $this->greaterThan(0)
            )
            ->willReturn(new \Illuminate\Database\Eloquent\Collection([$discoveredToilet]));

        $this->app->instance(\App\Services\GooglePlacesService::class, $mockPlacesService);

        // Query empty bounding box (10.0, 20.0 to 11.0, 21.0)
        $response = $this->getJson('/toilets/bounds/10.0/20.0/11.0/21.0');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains(999999, $ids);
    }
}
