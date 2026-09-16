<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\ToiletPhoto;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DetectDuplicateToiletsCommandTest extends TestCase
{
    private array $createdToiletIds = [];
    private array $createdPhotoIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdPhotoIds)) {
            DB::table('toilet_photos')->whereIn('id', $this->createdPhotoIds)->delete();
        }

        if (! empty($this->createdToiletIds)) {
            DB::table('toilet_revisions')->whereIn('toilet_id', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        parent::tearDown();
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $placeId = 'test_dry_run_place_' . uniqid();

        $toilet1 = Toilet::create([
            'name' => 'Original Toilet',
            'place_id' => $placeId,
            'status' => 'active',
            'is_qualified' => 1,
            'flagged' => 0,
        ]);
        $toilet2 = Toilet::create([
            'name' => 'Duplicate Toilet',
            'place_id' => $placeId,
            'status' => 'hidden',
            'is_qualified' => 0,
            'flagged' => 1,
        ]);

        $this->createdToiletIds = [$toilet1->id, $toilet2->id];

        $this->artisan('app:detect-duplicate-toilets', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN MODE')
            ->expectsOutputToContain('Total duplicate toilets to mark deleted:')
            ->assertSuccessful();

        $toilet1->refresh();
        $toilet2->refresh();

        $this->assertEquals('active', $toilet1->status);
        $this->assertEquals('hidden', $toilet2->status);
        $this->assertTrue((bool) $toilet2->flagged);
    }

    public function test_detects_and_deletes_duplicate_by_same_place_id(): void
    {
        $placeId = 'test_same_place_' . uniqid();

        $master = Toilet::create([
            'name' => 'Main Qualified Toilet',
            'place_id' => $placeId,
            'status' => 'active',
            'is_qualified' => 1,
            'flagged' => 0,
        ]);
        $duplicate = Toilet::create([
            'name' => 'Unqualified Duplicate',
            'place_id' => $placeId,
            'status' => 'hidden',
            'is_qualified' => 0,
            'flagged' => 1,
        ]);

        $this->createdToiletIds = [$master->id, $duplicate->id];

        $this->artisan('app:detect-duplicate-toilets')
            ->assertSuccessful();

        $master->refresh();
        $duplicate->refresh();

        $this->assertEquals('active', $master->status);
        $this->assertEquals('deleted', $duplicate->status);
        $this->assertFalse((bool) $duplicate->flagged);

        // Verify revision was recorded
        $revision = DB::table('toilet_revisions')
            ->where('toilet_id', $duplicate->id)
            ->where('source', 'admin_deduplication')
            ->first();
        $this->assertNotNull($revision);
    }

    public function test_detects_and_deletes_duplicate_by_coordinate_proximity(): void
    {
        // ~3 meters apart
        $lat1 = 52.520010;
        $lon1 = 13.405010;
        $lat2 = 52.520030;
        $lon2 = 13.405030;

        $master = Toilet::create([
            'name' => 'Master Toilet Coords',
            'lat' => $lat1,
            'lon' => $lon1,
            'status' => 'active',
            'is_qualified' => 1,
            'flagged' => 0,
        ]);
        $duplicate = Toilet::create([
            'name' => 'Duplicate Toilet Coords',
            'lat' => $lat2,
            'lon' => $lon2,
            'status' => 'active',
            'is_qualified' => 0,
            'flagged' => 1,
        ]);

        $this->createdToiletIds = [$master->id, $duplicate->id];

        $this->artisan('app:detect-duplicate-toilets', ['--distance' => '10', '--coords-only' => true])
            ->assertSuccessful();

        $master->refresh();
        $duplicate->refresh();

        $this->assertEquals('active', $master->status);
        $this->assertEquals('deleted', $duplicate->status);
        $this->assertFalse((bool) $duplicate->flagged);
    }

    public function test_relinks_photos_from_duplicate_to_master(): void
    {
        $placeId = 'test_photo_relink_' . uniqid();

        $master = Toilet::create([
            'name' => 'Master Toilet For Photos',
            'place_id' => $placeId,
            'status' => 'active',
            'is_qualified' => 1,
        ]);
        $duplicate = Toilet::create([
            'name' => 'Duplicate Toilet With Photo',
            'place_id' => $placeId,
            'status' => 'hidden',
            'is_qualified' => 0,
        ]);

        $this->createdToiletIds = [$master->id, $duplicate->id];

        $photoId = DB::table('toilet_photos')->insertGetId([
            'fk_toiletId' => $duplicate->id,
            'filename' => 'test_photo_' . uniqid() . '.jpg',
        ]);
        $this->createdPhotoIds[] = $photoId;

        $this->artisan('app:detect-duplicate-toilets')
            ->assertSuccessful();

        $photo = DB::table('toilet_photos')->where('id', $photoId)->first();
        $this->assertEquals($master->id, $photo->fk_toiletId);
    }

    public function test_safeguard_excludes_coarse_integer_coordinates(): void
    {
        // 51.0, 7.0 is a coarse/integer-truncated coordinate
        $toiletA = Toilet::create([
            'name' => 'Toilet Region A',
            'lat' => 51.000000,
            'lon' => 7.000000,
            'status' => 'active',
            'is_qualified' => 0,
        ]);
        $toiletB = Toilet::create([
            'name' => 'Toilet Region B',
            'lat' => 51.000000,
            'lon' => 7.000000,
            'status' => 'active',
            'is_qualified' => 0,
        ]);

        $this->createdToiletIds = [$toiletA->id, $toiletB->id];

        $this->artisan('app:detect-duplicate-toilets', ['--coords-only' => true])
            ->assertSuccessful();

        $toiletA->refresh();
        $toiletB->refresh();

        // Neither should be deleted because integer coordinates are protected by safeguard
        $this->assertEquals('active', $toiletA->status);
        $this->assertEquals('active', $toiletB->status);
    }
}
