<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Toilet;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ToiletBoundsSummaryTest extends TestCase
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

    public function test_bounds_returns_individual_toilets_when_area_below_threshold(): void
    {
        $toilet = Toilet::create([
            'name' => 'Real Local Toilet',
            'owner' => 'City Council',
            'lat' => 52.5100,
            'lon' => 13.4000,
            'status' => 'active',
            'is_qualified' => 1,
            'last_included' => null,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        DB::table('toilet_properties')->insert([
            ['fk_toiletId' => $toilet->id, 'type' => 'has_wheelchair_access', 'value' => '1', 'user_overridden' => 0],
            ['fk_toiletId' => $toilet->id, 'type' => 'public_accessible', 'value' => '1', 'user_overridden' => 0],
        ]);

        // Bounds: ~2.2 km x ~1.4 km = ~3 km² (< 50 km²)
        $response = $this->getJson('/toilets/bounds/52.50/13.39/52.52/13.41');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertNotEmpty($data);

        $found = collect($data)->firstWhere('id', $toilet->id);
        $this->assertNotNull($found);
        $this->assertSame('Real Local Toilet', $found['name']);
        $this->assertFalse($found['is_summary']);
        $this->assertSame(1, $found['summary_count']);
        $this->assertTrue($found['has_wheelchair_access']);

        // In detailed mode, last_included MUST be marked
        $toilet->refresh();
        $this->assertNotNull($toilet->last_included);
    }

    public function test_bounds_returns_clustered_summary_when_area_above_threshold(): void
    {
        $initialTime = now()->subDays(5)->startOfSecond();

        // Two toilets in the same ~2km grid cell around 52.505, 13.405
        $toilet1 = Toilet::create([
            'name' => 'Toilet 1',
            'owner' => 'Owner 1',
            'lat' => 52.5050,
            'lon' => 13.4050,
            'status' => 'active',
            'is_qualified' => 1,
            'last_included' => $initialTime,
        ]);
        $this->createdToiletIds[] = $toilet1->id;

        // Toilet 1 has wheelchair access
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toilet1->id,
            'type' => 'has_wheelchair_access',
            'value' => '1',
            'user_overridden' => 0,
        ]);

        $toilet2 = Toilet::create([
            'name' => 'Toilet 2',
            'owner' => 'Owner 2',
            'lat' => 52.5060,
            'lon' => 13.4060,
            'status' => 'active',
            'is_qualified' => 1,
            'last_included' => $initialTime,
        ]);
        $this->createdToiletIds[] = $toilet2->id;

        // Bounds: 1 degree x 1 degree (~7,500 km² > 50 km²)
        $response = $this->getJson('/toilets/bounds/52.0/13.0/53.0/14.0');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertNotEmpty($data);

        // All returned items must be summaries
        foreach ($data as $item) {
            $this->assertTrue($item['is_summary']);
            $this->assertLessThan(0, $item['id']);
            $this->assertMatchesRegularExpression('/\d+ Toilets? around .+/i', $item['name']);
            $this->assertMatchesRegularExpression('/\d+ Toilets? around .+/i', $item['owner']);
            $this->assertTrue($item['is_open']);
            $this->assertTrue($item['public_accessible']);
        }

        // Find the summary item for our cell
        $cluster = collect($data)->first(function ($item) {
            $b = $item['summary_bounds'];

            return $b['south'] <= 52.5050 && $b['north'] >= 52.5050
                && $b['west'] <= 13.4050 && $b['east'] >= 13.4050;
        });

        $this->assertNotNull($cluster, 'Grid cluster for the test toilets was not found');
        $this->assertGreaterThanOrEqual(2, $cluster['summary_count']);
        $this->assertTrue($cluster['has_wheelchair_access'], 'Wheelchair access must be true if at least one toilet in cell has it');
        $this->assertIsArray($cluster['summary_bounds']);
        $this->assertArrayHasKey('south', $cluster['summary_bounds']);
        $this->assertArrayHasKey('west', $cluster['summary_bounds']);
        $this->assertArrayHasKey('north', $cluster['summary_bounds']);
        $this->assertArrayHasKey('east', $cluster['summary_bounds']);
        $this->assertLessThan($cluster['summary_bounds']['north'], $cluster['summary_bounds']['south']);
        $this->assertLessThan($cluster['summary_bounds']['east'], $cluster['summary_bounds']['west']);
        $this->assertNotNull($cluster['summary_location_name']);
        $this->assertNotEmpty($cluster['summary_location_name']);

        // In summary mode, last_included MUST NOT be updated!
        $toilet1->refresh();
        $toilet2->refresh();
        $this->assertEquals($initialTime->timestamp, $toilet1->last_included?->timestamp);
        $this->assertEquals($initialTime->timestamp, $toilet2->last_included?->timestamp);
    }

    public function test_bounds_summary_does_not_call_google_places_discovery(): void
    {
        $mockPlacesService = $this->createMock(GooglePlacesService::class);
        $mockPlacesService->expects($this->never())->method('discoverToiletsNearby');

        $this->app->instance(GooglePlacesService::class, $mockPlacesService);

        // Query empty huge bounds (0.0..1.0, 0.0..1.0 = ~12,000 km²)
        $response = $this->getJson('/toilets/bounds/0.0/0.0/1.0/1.0');
        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }
}
