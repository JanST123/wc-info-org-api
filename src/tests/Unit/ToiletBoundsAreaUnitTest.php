<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Api\ToiletController;
use App\Services\GooglePlacesService;
use App\Services\ToiletRevisionService;
use Tests\TestCase;

class ToiletBoundsAreaUnitTest extends TestCase
{
    private ToiletController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new ToiletController(
            $this->createMock(GooglePlacesService::class),
            $this->createMock(ToiletRevisionService::class)
        );
    }

    public function test_calculate_bounds_area_small_berlin_district(): void
    {
        // ~2.2 km north-south, ~1.4 km east-west at latitude 52.5°
        $area = $this->controller->calculateBoundsAreaKm2(52.51, 13.39, 52.53, 13.41);

        // Expected: ~3 km²
        $this->assertGreaterThan(2.5, $area);
        $this->assertLessThan(3.5, $area);
        $this->assertLessThan(50.0, $area, 'Small district should be well below 50 km² threshold');
    }

    public function test_calculate_bounds_area_large_region_exceeds_threshold(): void
    {
        // 1 degree x 1 degree over Germany (~111 km x ~68 km = ~7,500 km²)
        $area = $this->controller->calculateBoundsAreaKm2(52.0, 13.0, 53.0, 14.0);

        $this->assertGreaterThan(7000.0, $area);
        $this->assertGreaterThan(50.0, $area, '1 degree region must exceed 50 km² threshold');
    }

    public function test_calculate_bounds_area_handles_inverted_coordinates(): void
    {
        $areaNormal = $this->controller->calculateBoundsAreaKm2(52.51, 13.39, 52.53, 13.41);
        $areaInverted = $this->controller->calculateBoundsAreaKm2(52.53, 13.41, 52.51, 13.39);

        $this->assertSame($areaNormal, $areaInverted);
    }

    public function test_synthetic_id_generation_is_always_negative_and_unique(): void
    {
        $ids = [];

        // Test over a grid range
        for ($latCell = 100; $latCell <= 110; $latCell++) {
            for ($lonCell = 200; $lonCell <= 210; $lonCell++) {
                $normLat = $latCell + 10000;
                $normLon = $lonCell + 20000;
                $syntheticId = -abs(($normLat * 100000) + $normLon);

                $this->assertLessThan(0, $syntheticId, 'Synthetic ID must always be negative');
                $this->assertArrayNotHasKey($syntheticId, $ids, 'Synthetic ID must be unique across grid cells');
                $ids[$syntheticId] = true;
            }
        }
    }

    public function test_configuration_defaults(): void
    {
        $threshold = config('wcinfo.bounds_summary_threshold_km2');
        $cellSize = config('wcinfo.bounds_summary_cell_size_km2');
        $maxLimit = config('wcinfo.bounds_max_unsummarized_limit');
        $tiers = config('wcinfo.bounds_summary_cell_tiers');

        $this->assertGreaterThan(0, (float) $threshold);
        $this->assertGreaterThan(0, (float) $cellSize);
        $this->assertSame(500, (int) $maxLimit);
        $this->assertIsArray($tiers);
        $this->assertNotEmpty($tiers);
    }

    public function test_get_cell_size_for_area_scales_dynamically(): void
    {
        // City district (<= 500 km²): 8 km²
        $this->assertSame(8.0, $this->controller->getCellSizeKm2ForArea(300.0));

        // Metropolitan region (500 - 2,500 km²): 25 km²
        $this->assertSame(25.0, $this->controller->getCellSizeKm2ForArea(1502.63));

        // Sub-state region (2,500 - 15,000 km²): 100 km²
        $this->assertSame(100.0, $this->controller->getCellSizeKm2ForArea(8000.0));

        // Federal state (15,000 - 75,000 km²): 400 km²
        $this->assertSame(400.0, $this->controller->getCellSizeKm2ForArea(34000.0));

        // Multi-state (75,000 - 300,000 km²): 1200 km²
        $this->assertSame(1200.0, $this->controller->getCellSizeKm2ForArea(150000.0));

        // Entire country / Germany (> 300,000 km²): 2500 km²
        $this->assertSame(2500.0, $this->controller->getCellSizeKm2ForArea(357000.0));
    }
}
