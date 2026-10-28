<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletRevision;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnflagPublicBathroomToiletsCommandTest extends TestCase
{
    private array $createdToiletIds = [];

    private array $createdPlaceIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_command_unflags_flagged_toilet_with_public_bathroom_type_when_opening_hours_set_to_null(): void
    {
        // 1. Toilet with place type public_bathroom and place_opening_hours set to null in last_diff
        $placeId1 = 'place_pb_'.uniqid();
        Place::create([
            'place_id' => $placeId1,
            'data' => [
                'id' => $placeId1,
                'displayName' => ['text' => 'Public Restroom Park'],
                'types' => ['public_bathroom', 'point_of_interest'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId1;

        $toilet1 = Toilet::create([
            'name' => 'Public Park Toilet',
            'status' => 'active',
            'flagged' => true,
            'place_id' => $placeId1,
            'last_diff' => [
                'place_opening_hours' => [
                    'old' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
                    'new' => null,
                ],
                'flagged' => [
                    'old' => false,
                    'new' => true,
                ],
            ],
        ]);
        $this->createdToiletIds[] = $toilet1->id;

        // 2. Toilet with place type restaurant (NOT public_bathroom) and place_opening_hours set to null -> should STAY flagged
        $placeId2 = 'place_rest_'.uniqid();
        Place::create([
            'place_id' => $placeId2,
            'data' => [
                'id' => $placeId2,
                'displayName' => ['text' => 'Italian Restaurant'],
                'types' => ['restaurant', 'food'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId2;

        $toilet2 = Toilet::create([
            'name' => 'Restaurant Restroom',
            'status' => 'active',
            'flagged' => true,
            'place_id' => $placeId2,
            'last_diff' => [
                'place_opening_hours' => [
                    'old' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
                    'new' => null,
                ],
                'flagged' => [
                    'old' => false,
                    'new' => true,
                ],
            ],
        ]);
        $this->createdToiletIds[] = $toilet2->id;

        // 3. Toilet with NO place assigned and place_opening_hours set to null -> should STAY flagged
        $toilet3 = Toilet::create([
            'name' => 'Orphan Toilet',
            'status' => 'active',
            'flagged' => true,
            'place_id' => null,
            'last_diff' => [
                'place_opening_hours' => [
                    'old' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
                    'new' => null,
                ],
            ],
        ]);
        $this->createdToiletIds[] = $toilet3->id;

        // 4. Toilet with place type public_bathroom, but last update did NOT set opening hours to null (e.g. only address changed) -> should STAY flagged
        $placeId4 = 'place_pb_'.uniqid();
        Place::create([
            'place_id' => $placeId4,
            'data' => [
                'id' => $placeId4,
                'displayName' => ['text' => 'City Hall Toilet'],
                'types' => ['public_bathroom'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId4;

        $toilet4 = Toilet::create([
            'name' => 'City Hall Toilet',
            'status' => 'active',
            'flagged' => true,
            'place_id' => $placeId4,
            'last_diff' => [
                'address' => [
                    'old' => 'Old Address 1',
                    'new' => 'New Address 2',
                ],
            ],
        ]);
        $this->createdToiletIds[] = $toilet4->id;

        // --- Step A: Test dry-run mode ---
        $this->artisan('app:unflag-public-bathrooms-without-opening-hours', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode')
            ->expectsOutputToContain('1 toilet(s) would be unflagged and set to 24/7 opening hours');

        // Verify database is NOT modified in dry-run mode
        $toilet1Fresh = Toilet::find($toilet1->id);
        $this->assertTrue((bool) $toilet1Fresh->flagged);

        // --- Step B: Test actual execution ---
        $this->artisan('app:unflag-public-bathrooms-without-opening-hours')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully unflagged and set 24/7 opening hours for 1 toilet(s) assigned to places of type "public_bathroom"');

        // Verify toilet1 was unflagged and received 24/7 opening hours with user_overridden = 1
        $toilet1Fresh = Toilet::find($toilet1->id);
        $this->assertFalse((bool) $toilet1Fresh->flagged);
        $prop1 = DB::table('toilet_properties')->where('fk_toiletId', $toilet1->id)->where('type', 'place_opening_hours')->first();
        $this->assertNotNull($prop1);
        $this->assertSame('[{"open":{"day":0,"hour":0,"minute":0}}]', $prop1->value);
        $this->assertEquals(1, $prop1->user_overridden);

        // Verify other toilets remain flagged
        $toilet2Fresh = Toilet::find($toilet2->id);
        $this->assertTrue((bool) $toilet2Fresh->flagged);

        $toilet3Fresh = Toilet::find($toilet3->id);
        $this->assertTrue((bool) $toilet3Fresh->flagged);

        $toilet4Fresh = Toilet::find($toilet4->id);
        $this->assertTrue((bool) $toilet4Fresh->flagged);
    }

    public function test_command_resolves_diff_from_latest_revision_when_last_diff_is_null(): void
    {
        $placeId = 'place_pb_'.uniqid();
        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Central Station Restroom'],
                'types' => ['public_bathroom'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId;

        $toilet = Toilet::create([
            'name' => 'Station Restroom',
            'status' => 'active',
            'flagged' => true,
            'place_id' => $placeId,
            'last_diff' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        ToiletRevision::create([
            'toilet_id' => $toilet->id,
            'version' => 1,
            'source' => 'crawl',
            'toilet_data' => [],
            'properties_data' => [],
            'diff' => [
                'place_opening_hours' => [
                    'old' => '[{"open":{"day":0,"hour":0,"minute":0}}]',
                    'new' => null,
                ],
            ],
            'created_at' => now(),
        ]);

        $this->artisan('app:unflag-public-bathroom-toilets')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully unflagged and set 24/7 opening hours for 1 toilet(s)');

        $this->assertFalse((bool) Toilet::find($toilet->id)->flagged);
    }

    public function test_command_supports_only_hours_option(): void
    {
        $placeId = 'place_pb_'.uniqid();
        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Public Restroom Multi Change'],
                'types' => ['public_bathroom'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId;

        // Toilet with substantive address change in addition to opening hours set to null
        $toilet = Toilet::create([
            'name' => 'Multi Change Toilet',
            'status' => 'active',
            'flagged' => true,
            'place_id' => $placeId,
            'last_diff' => [
                'place_opening_hours' => [
                    'old' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
                    'new' => null,
                ],
                'address' => [
                    'old' => 'Old Address',
                    'new' => 'New Address',
                ],
                'flagged' => [
                    'old' => false,
                    'new' => true,
                ],
            ],
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // Running with --only-hours should skip this toilet because it has an address change
        $this->artisan('app:unflag-public-bathrooms', ['--only-hours' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('No flagged toilets found where the last update set place_opening_hours to null');

        $this->assertTrue((bool) Toilet::find($toilet->id)->flagged);

        // Running without --only-hours should unflag it
        $this->artisan('app:unflag-public-bathrooms')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully unflagged and set 24/7 opening hours for 1 toilet(s)');

        $this->assertFalse((bool) Toilet::find($toilet->id)->flagged);
    }

    public function test_command_supports_include_restroom_types_option(): void
    {
        $placeId = 'place_restroom_'.uniqid();
        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Highway Rest Area Restroom'],
                'types' => ['restroom'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId;

        $toilet = Toilet::create([
            'name' => 'Highway Restroom',
            'status' => 'active',
            'flagged' => true,
            'place_id' => $placeId,
            'last_diff' => [
                'place_opening_hours' => [
                    'old' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
                    'new' => null,
                ],
            ],
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // Without --include-restroom-types, type 'restroom' is skipped
        $this->artisan('app:unflag-public-bathrooms')
            ->assertSuccessful()
            ->expectsOutputToContain('none of them are assigned to a place with type "public_bathroom"');

        $this->assertTrue((bool) Toilet::find($toilet->id)->flagged);

        // With --include-restroom-types, it is unflagged
        $this->artisan('app:unflag-public-bathrooms', ['--include-restroom-types' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully unflagged and set 24/7 opening hours for 1 toilet(s)');

        $this->assertFalse((bool) Toilet::find($toilet->id)->flagged);
    }
}
