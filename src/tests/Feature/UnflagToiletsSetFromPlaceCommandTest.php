<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletRevision;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnflagToiletsSetFromPlaceCommandTest extends TestCase
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

    public function test_command_unflags_flagged_toilet_when_fields_only_set_from_null_to_place_data(): void
    {
        $placeId = 'place_test_'.uniqid();
        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Grand Central Cafe'],
                'formattedAddress' => 'Main Street 100, Berlin',
                'websiteUri' => 'https://grandcentral.berlin',
                'location' => [
                    'latitude' => 52.520008,
                    'longitude' => 13.404954,
                ],
                'types' => ['cafe', 'food'],
                'restroom' => true,
            ],
        ]);
        $this->createdPlaceIds[] = $placeId;

        // Toilet A: Fields set from null to place data -> SHOULD BE UNFLAGGED
        $toiletA = Toilet::create([
            'name' => 'Toilet A',
            'status' => 'active',
            'place_id' => $placeId,
            'owner' => 'Grand Central Cafe',
            'flagged' => true,
            'last_diff' => [
                'owner' => ['old' => null, 'new' => 'Grand Central Cafe'],
                'address' => ['old' => null, 'new' => 'Main Street 100, Berlin'],
                'website' => ['old' => null, 'new' => 'https://grandcentral.berlin'],
                'is_unisex' => ['old' => '0', 'new' => '1'],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletA->id;

        // Toilet B: Owner was NOT null (was 'Old Cafe'), but set to current place name -> SHOULD BE UNFLAGGED
        $toiletB = Toilet::create([
            'name' => 'Toilet B',
            'status' => 'active',
            'place_id' => $placeId,
            'owner' => 'Grand Central Cafe',
            'flagged' => true,
            'last_diff' => [
                'owner' => ['old' => 'Old Cafe', 'new' => 'Grand Central Cafe'],
                'address' => ['old' => null, 'new' => 'Main Street 100, Berlin'],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletB->id;

        // Toilet C: Website set to null from an old website -> SHOULD BE UNFLAGGED
        $toiletC = Toilet::create([
            'name' => 'Toilet C',
            'status' => 'active',
            'place_id' => $placeId,
            'flagged' => true,
            'last_diff' => [
                'website' => ['old' => 'https://old-site.com', 'new' => null],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletC->id;

        // Toilet D: Address was NOT null (was 'Old Street 1') -> SHOULD REMAIN FLAGGED
        $toiletD = Toilet::create([
            'name' => 'Toilet D',
            'status' => 'active',
            'place_id' => $placeId,
            'flagged' => true,
            'last_diff' => [
                'address' => ['old' => 'Old Street 1', 'new' => 'Main Street 100, Berlin'],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletD->id;

        // Toilet E: Owner set to wrong name (mismatch with place) -> SHOULD REMAIN FLAGGED
        $toiletE = Toilet::create([
            'name' => 'Toilet E',
            'status' => 'active',
            'place_id' => $placeId,
            'flagged' => true,
            'last_diff' => [
                'owner' => ['old' => 'Old Cafe', 'new' => 'Arbitrary Wrong Cafe'],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletE->id;

        // Toilet F: No place assigned -> SHOULD REMAIN FLAGGED
        $toiletF = Toilet::create([
            'name' => 'Toilet F',
            'status' => 'active',
            'place_id' => null,
            'flagged' => true,
            'last_diff' => [
                'address' => ['old' => null, 'new' => 'Main Street 100, Berlin'],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletF->id;

        // Toilet G: lat/lon old was whole number (52/13), new matches place coordinates -> SHOULD BE UNFLAGGED
        $toiletG = Toilet::create([
            'name' => 'Toilet G',
            'status' => 'active',
            'place_id' => $placeId,
            'flagged' => true,
            'last_diff' => [
                'lat' => ['old' => 52, 'new' => 52.520008],
                'lon' => ['old' => 13, 'new' => 13.404954],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletG->id;

        // Toilet H: lat old was already a float (52.519000) -> SHOULD REMAIN FLAGGED
        $toiletH = Toilet::create([
            'name' => 'Toilet H',
            'status' => 'active',
            'place_id' => $placeId,
            'flagged' => true,
            'last_diff' => [
                'lat' => ['old' => 52.519000, 'new' => 52.520008],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletH->id;

        // 1. Test DRY-RUN mode
        $this->artisan('app:unflag-toilets-set-from-place', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode')
            ->expectsOutputToContain('4 toilet(s) would be unflagged');

        // Confirm still flagged after dry-run
        $this->assertTrue((bool) Toilet::find($toiletA->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletB->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletC->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletD->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletE->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletF->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletG->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletH->id)->flagged);

        // 2. Test LIVE execution
        $this->artisan('app:unflag-toilets-set-from-place')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully unflagged 4 toilet(s)');

        // Confirm Toilets A, B, C, G are unflagged, and D, E, F, H remain flagged
        $this->assertFalse((bool) Toilet::find($toiletA->id)->flagged);
        $this->assertFalse((bool) Toilet::find($toiletB->id)->flagged);
        $this->assertFalse((bool) Toilet::find($toiletC->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletD->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletE->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletF->id)->flagged);
        $this->assertFalse((bool) Toilet::find($toiletG->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletH->id)->flagged);
    }

    public function test_command_works_with_revision_diff(): void
    {
        $placeId = 'place_rev_'.uniqid();
        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Revision Cafe'],
                'formattedAddress' => 'Rev Street 1, Berlin',
                'types' => ['cafe'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId;

        $toilet = Toilet::create([
            'name' => 'Toilet Rev',
            'status' => 'active',
            'place_id' => $placeId,
            'flagged' => true,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        ToiletRevision::create([
            'toilet_id' => $toilet->id,
            'version' => 1,
            'source' => 'update-from-place',
            'toilet_data' => ['name' => 'Toilet Rev'],
            'properties_data' => [],
            'diff' => [
                'owner' => ['old' => null, 'new' => 'Revision Cafe'],
                'address' => ['old' => null, 'new' => 'Rev Street 1, Berlin'],
                'flagged' => ['old' => false, 'new' => true],
            ],
            'created_at' => now(),
        ]);

        $this->artisan('app:unflag-toilets-from-place-data')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully unflagged 1 toilet(s)');

        $this->assertFalse((bool) Toilet::find($toilet->id)->flagged);
    }
}
