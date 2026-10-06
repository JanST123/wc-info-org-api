<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\ToiletRevision;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetUnisexToiletPropertiesCommandTest extends TestCase
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

    public function test_command_sets_is_unisex_for_toilets_without_gender_separated(): void
    {
        // Toilet 1: No properties at all -> should become unisex = 1
        $toilet1 = Toilet::create([
            'name' => 'Toilet No Props',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet1->id;

        // Toilet 2: Gender separated = 1 -> should NOT be modified
        $toilet2 = Toilet::create([
            'name' => 'Toilet Gender Separated',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet2->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet2->id,
            'type' => 'is_gender_separated',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        // Toilet 3: Gender separated = 0 -> should become unisex = 1
        $toilet3 = Toilet::create([
            'name' => 'Toilet Gender Separated Zero',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet3->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet3->id,
            'type' => 'is_gender_separated',
            'value' => '0',
            'user_overridden' => 0,
        ]);

        // Toilet 4: Already unisex = 1 -> should remain 1
        $toilet4 = Toilet::create([
            'name' => 'Toilet Already Unisex',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet4->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet4->id,
            'type' => 'is_unisex',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        // Toilet 5: Unisex = 0 and no gender separated -> should be updated to unisex = 1
        $toilet5 = Toilet::create([
            'name' => 'Toilet Unisex Zero',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet5->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet5->id,
            'type' => 'is_unisex',
            'value' => '0',
            'user_overridden' => 0,
        ]);

        // 1. Dry run should not make changes
        $this->artisan('app:set-unisex-toilet-properties', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode');

        $this->assertNull(
            DB::table('toilet_properties')->where('fk_toiletId', $toilet1->id)->where('type', 'is_unisex')->first()
        );
        $prop5Before = DB::table('toilet_properties')->where('fk_toiletId', $toilet5->id)->where('type', 'is_unisex')->first();
        $this->assertSame('0', $prop5Before?->value);

        // 2. Live execution
        $this->artisan('app:set-unisex-toilet-properties')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully set is_unisex property to 1');

        // Verify Toilet 1 has is_unisex = 1
        $prop1 = DB::table('toilet_properties')->where('fk_toiletId', $toilet1->id)->where('type', 'is_unisex')->first();
        $this->assertNotNull($prop1);
        $this->assertSame('1', $prop1->value);

        // Verify Toilet 2 does NOT have is_unisex property
        $prop2 = DB::table('toilet_properties')->where('fk_toiletId', $toilet2->id)->where('type', 'is_unisex')->first();
        $this->assertNull($prop2);

        // Verify Toilet 3 has is_unisex = 1
        $prop3 = DB::table('toilet_properties')->where('fk_toiletId', $toilet3->id)->where('type', 'is_unisex')->first();
        $this->assertNotNull($prop3);
        $this->assertSame('1', $prop3->value);

        // Verify Toilet 4 remains is_unisex = 1
        $prop4 = DB::table('toilet_properties')->where('fk_toiletId', $toilet4->id)->where('type', 'is_unisex')->first();
        $this->assertNotNull($prop4);
        $this->assertSame('1', $prop4->value);

        // Verify Toilet 5 updated to is_unisex = 1
        $prop5 = DB::table('toilet_properties')->where('fk_toiletId', $toilet5->id)->where('type', 'is_unisex')->first();
        $this->assertNotNull($prop5);
        $this->assertSame('1', $prop5->value);
    }

    public function test_command_unflags_toilets_where_last_change_was_setting_is_unisex_0_to_1(): void
    {
        // Toilet A: Flagged, last_diff is only is_unisex 0 -> 1 and flagged -> should be unflagged
        $toiletA = Toilet::create([
            'name' => 'Flagged Unisex Change',
            'status' => 'active',
            'flagged' => true,
            'last_diff' => [
                'is_unisex' => ['old' => '0', 'new' => '1'],
                'flagged' => ['old' => false, 'new' => true],
            ],
        ]);
        $this->createdToiletIds[] = $toiletA->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toiletA->id,
            'type' => 'is_unisex',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        // Toilet B: Flagged, diff recorded in latestRevision only -> should be unflagged
        $toiletB = Toilet::create([
            'name' => 'Flagged Revision Unisex Change',
            'status' => 'active',
            'flagged' => true,
            'last_diff' => null,
            'version' => 2,
        ]);
        $this->createdToiletIds[] = $toiletB->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toiletB->id,
            'type' => 'is_unisex',
            'value' => '1',
            'user_overridden' => 0,
        ]);
        ToiletRevision::create([
            'toilet_id' => $toiletB->id,
            'version' => 2,
            'source' => 'auto_crawl',
            'toilet_data' => [],
            'properties_data' => [],
            'diff' => [
                'is_unisex' => ['old' => null, 'new' => '1'],
                'is_gender_separated' => ['old' => '0', 'new' => '0'],
            ],
            'created_at' => now(),
        ]);

        // Toilet C: Flagged, but last_diff has website change too -> should REMAINS flagged
        $toiletC = Toilet::create([
            'name' => 'Flagged Multi Change',
            'status' => 'active',
            'flagged' => true,
            'last_diff' => [
                'is_unisex' => ['old' => '0', 'new' => '1'],
                'website' => ['old' => null, 'new' => 'https://example.com'],
            ],
        ]);
        $this->createdToiletIds[] = $toiletC->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toiletC->id,
            'type' => 'is_unisex',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        // Toilet D: Flagged, no diff -> should REMAIN flagged
        $toiletD = Toilet::create([
            'name' => 'Flagged No Diff',
            'status' => 'active',
            'flagged' => true,
            'last_diff' => null,
        ]);
        $this->createdToiletIds[] = $toiletD->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toiletD->id,
            'type' => 'is_unisex',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        // 1. Dry run should not unflag anything
        $this->artisan('app:set-unisex-toilet-properties', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode');

        $this->assertTrue((bool) Toilet::find($toiletA->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletB->id)->flagged);

        // 2. Live run should unflag Toilet A and Toilet B, but not C and D
        $this->artisan('app:set-unisex-toilet-properties')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully unflagged');

        $this->assertFalse((bool) Toilet::find($toiletA->id)->flagged);
        $this->assertFalse((bool) Toilet::find($toiletB->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletC->id)->flagged);
        $this->assertTrue((bool) Toilet::find($toiletD->id)->flagged);
    }
}
