<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\Type;
use App\Models\TypeXPlace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PresetPublicAccessibleTypesCommandTest extends TestCase
{
    private array $createdToiletIds = [];

    private array $createdPlaceIds = [];

    private array $createdTypeIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            DB::table('type_x_place')->whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        if (! empty($this->createdTypeIds)) {
            DB::table('types')->whereIn('id', $this->createdTypeIds)->delete();
        }

        parent::tearDown();
    }

    public function test_command_presets_public_accessible_and_respects_overrides(): void
    {
        // 1. Setup matching type: park
        $parkType = Type::firstOrCreate(['type' => 'park']);
        $this->createdTypeIds[] = $parkType->id;

        // 2. Setup non-matching type: accounting
        $accountingType = Type::firstOrCreate(['type' => 'accounting']);
        $this->createdTypeIds[] = $accountingType->id;

        // Place A: Linked to 'park'
        $placeIdA = 'place_test_park_'.uniqid();
        $this->createdPlaceIds[] = $placeIdA;
        TypeXPlace::create(['place_id' => $placeIdA, 'type_id' => $parkType->id]);

        // Place B: Linked to 'accounting'
        $placeIdB = 'place_test_accounting_'.uniqid();
        $this->createdPlaceIds[] = $placeIdB;
        TypeXPlace::create(['place_id' => $placeIdB, 'type_id' => $accountingType->id]);

        // Toilet 1: In park, currently has no public_accessible property
        $toilet1 = Toilet::create([
            'name' => 'Park WC',
            'place_id' => $placeIdA,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet1->id;

        // Toilet 2: In park, but user explicitly marked public_accessible = '0' (overridden)
        $toilet2 = Toilet::create([
            'name' => 'Overridden Park WC',
            'place_id' => $placeIdA,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet2->id;
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet2->id,
            'type' => 'public_accessible',
            'value' => '0',
            'user_overridden' => 1,
        ]);

        // Toilet 3: In office (accounting)
        $toilet3 = Toilet::create([
            'name' => 'Office WC',
            'place_id' => $placeIdB,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet3->id;

        // Run in DRY-RUN mode first
        $this->artisan('app:preset-public-accessible-types', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode');

        // Check Toilet 1 is still unset after dry run
        $prop1Dry = DB::table('toilet_properties')->where('fk_toiletId', $toilet1->id)->where('type', 'public_accessible')->first();
        $this->assertNull($prop1Dry);

        // Run live migration
        $this->artisan('app:preset-public-accessible-types')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully preset public_accessible');

        // Toilet 1 must now have public_accessible = '1'
        $prop1 = DB::table('toilet_properties')->where('fk_toiletId', $toilet1->id)->where('type', 'public_accessible')->first();
        $this->assertNotNull($prop1);
        $this->assertSame('1', $prop1->value);
        $this->assertSame(0, (int) $prop1->user_overridden);

        // Toilet 2 must remain '0' (user overridden)
        $prop2 = DB::table('toilet_properties')->where('fk_toiletId', $toilet2->id)->where('type', 'public_accessible')->first();
        $this->assertNotNull($prop2);
        $this->assertSame('0', $prop2->value);
        $this->assertSame(1, (int) $prop2->user_overridden);

        // Toilet 3 must remain unset (non-public type)
        $prop3 = DB::table('toilet_properties')->where('fk_toiletId', $toilet3->id)->where('type', 'public_accessible')->first();
        $this->assertNull($prop3);
    }
}
