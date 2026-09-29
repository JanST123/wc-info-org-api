<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Type;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrioritizePlaceTypesCommandTest extends TestCase
{
    private array $createdTypeIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdTypeIds)) {
            DB::table('types')->whereIn('id', $this->createdTypeIds)->delete();
        }

        parent::tearDown();
    }

    public function test_command_prioritizes_place_types_and_supports_dry_run(): void
    {
        // 1. Setup a prioritized type (e.g., test_cafe_xxx) and a non-prioritized type (e.g., test_insurance_xxx)
        $uniqueCafe = 'test_cafe_' . uniqid();
        $uniqueInsurance = 'test_insurance_' . uniqid();

        // Temporarily configure prioritized types
        config(['wcinfo.prioritized_place_types' => [
            $uniqueCafe,
            'public_bathroom',
            'bar',
            'cafe',
        ]]);

        $cafeType = Type::create([
            'type' => $uniqueCafe,
            'priorize' => 0,
        ]);
        $this->createdTypeIds[] = $cafeType->id;

        $insuranceType = Type::create([
            'type' => $uniqueInsurance,
            'priorize' => 1, // Start as 1 to test reset to 0
        ]);
        $this->createdTypeIds[] = $insuranceType->id;

        // 2. Test DRY-RUN mode
        $this->artisan('app:prioritize-place-types', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode');

        // Verify no changes were made in dry-run mode
        $this->assertSame(0, (int) DB::table('types')->where('id', $cafeType->id)->value('priorize'));
        $this->assertSame(1, (int) DB::table('types')->where('id', $insuranceType->id)->value('priorize'));

        // 3. Test LIVE execution
        $this->artisan('app:prioritize-place-types')
            ->assertSuccessful()
            ->expectsOutputToContain("Successfully updated 'types' table");

        // Verify updates
        $this->assertSame(1, (int) DB::table('types')->where('id', $cafeType->id)->value('priorize'));
        $this->assertSame(0, (int) DB::table('types')->where('id', $insuranceType->id)->value('priorize'));

        // 4. Test execution via alias
        $this->artisan('app:priorize-place-types', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN mode');
    }

    public function test_type_model_casts_and_fillable(): void
    {
        $uniqueType = 'test_type_' . uniqid();
        $type = Type::create([
            'type' => $uniqueType,
            'priorize' => 1,
        ]);
        $this->createdTypeIds[] = $type->id;

        $this->assertSame(1, $type->priorize);
        $this->assertIsInt($type->priorize);

        $fresh = Type::find($type->id);
        $this->assertNotNull($fresh);
        $this->assertSame(1, $fresh->priorize);
        $this->assertIsInt($fresh->priorize);
    }
}
