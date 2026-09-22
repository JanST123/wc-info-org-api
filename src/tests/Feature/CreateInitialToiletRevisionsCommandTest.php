<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\ToiletRevision;
use App\Services\ToiletRevisionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateInitialToiletRevisionsCommandTest extends TestCase
{
    private array $createdToiletIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        parent::tearDown();
    }

    public function test_dry_run_previews_toilets_without_writing_revisions(): void
    {
        $toilet = Toilet::create([
            'name' => 'Toilet Without Rev',
            'owner' => 'Owner Test',
            'status' => 'active',
            'lat' => 52.5,
            'lon' => 13.4,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $this->artisan('app:create-initial-toilet-revisions', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode');

        $this->assertFalse(ToiletRevision::where('toilet_id', $toilet->id)->exists());
    }

    public function test_command_creates_initial_revision_with_snapshot_and_properties(): void
    {
        $revisionService = app(ToiletRevisionService::class);

        // Toilet 1: Has no revision, has 2 properties
        $toilet1 = Toilet::create([
            'name' => 'Toilet 1 New',
            'owner' => 'City Park Services',
            'status' => 'active',
            'lat' => 52.5123,
            'lon' => 13.4123,
            'place_id' => 'place_abc_123',
            'is_qualified' => 1,
            'flagged' => 0,
        ]);
        $this->createdToiletIds[] = $toilet1->id;

        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $toilet1->id, 'type' => 'website', 'value' => 'https://toilet1.org', 'user_overridden' => 0],
            ['fk_toiletId' => $toilet1->id, 'type' => 'is_unisex', 'value' => '1', 'user_overridden' => 0],
        ]);

        // Toilet 2: Already has an existing revision
        $toilet2 = Toilet::create([
            'name' => 'Toilet 2 Existing',
            'owner' => 'Cafe Central',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet2->id;
        $existingRev = $revisionService->recordRevision($toilet2, 'initial', null, 'First version');

        // Execute command
        $this->artisan('app:create-initial-toilet-revisions')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully created');

        // Verify Toilet 1 now has an initial revision
        $rev1 = ToiletRevision::where('toilet_id', $toilet1->id)->first();
        $this->assertNotNull($rev1);
        $this->assertSame(1, $rev1->version);
        $this->assertSame('initial', $rev1->source);
        $this->assertSame('Initial snapshot', $rev1->summary);
        $this->assertNull($rev1->diff);

        $tData = $rev1->toilet_data;
        $this->assertSame('Toilet 1 New', $tData['name']);
        $this->assertSame('City Park Services', $tData['owner']);
        $this->assertSame(52.5123, $tData['lat']);
        $this->assertSame(13.4123, $tData['lon']);
        $this->assertSame('place_abc_123', $tData['place_id']);
        $this->assertTrue($tData['is_qualified']);

        $pData = $rev1->properties_data;
        $this->assertSame('https://toilet1.org', $pData['website']);
        $this->assertSame('1', $pData['is_unisex']);

        // Verify Toilet 2 was untouched and still only has 1 revision
        $this->assertSame(1, ToiletRevision::where('toilet_id', $toilet2->id)->count());
        $this->assertSame($existingRev->id, ToiletRevision::where('toilet_id', $toilet2->id)->first()->id);
    }
}
