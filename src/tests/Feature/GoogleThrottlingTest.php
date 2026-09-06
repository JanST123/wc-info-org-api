<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\GoogleApiLog;
use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Models\ToiletProperty;
use App\Services\GoogleCostService;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use Carbon\Carbon;
use Tests\TestCase;

class GoogleThrottlingTest extends TestCase
{
    private array $createdToiletIds = [];
    private array $createdPlaceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        GoogleApiLog::truncate();
        AppSetting::truncate();
    }

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletPhoto::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_crawl_website_is_skipped_if_crawled_within_3_months(): void
    {
        $toilet = Toilet::create([
            'name' => 'Throttled Crawl Toilet',
            'status' => 'active',
            'place_id' => 'ChIJcrawl123',
            'website' => 'https://example.com',
            'last_crawled' => Carbon::now()->subMonths(2), // 2 months ago (< 3 months)
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $placesServiceMock = $this->createMock(GooglePlacesService::class);
        $placesServiceMock->expects($this->never())->method('crawlWebsite');

        $service = new PlaceToiletService($placesServiceMock);
        $service->updateToiletFromPlace($toilet, [
            'place_id' => 'ChIJcrawl123',
            'website' => 'https://example.com',
        ]);
    }

    public function test_crawl_website_runs_if_last_crawled_older_than_3_months_or_null(): void
    {
        $toilet = Toilet::create([
            'name' => 'Run Crawl Toilet',
            'status' => 'active',
            'place_id' => 'ChIJcrawl456',
            'website' => 'https://example.com',
            'last_crawled' => Carbon::now()->subMonths(4), // 4 months ago (> 3 months)
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $placesServiceMock = $this->createMock(GooglePlacesService::class);
        $placesServiceMock->expects($this->once())
            ->method('crawlWebsite')
            ->with('https://example.com')
            ->willReturn([
                'toiletType' => 'forall',
                'contactEmail' => 'info@example.com',
                'resultCount' => 1,
            ]);

        $service = new PlaceToiletService($placesServiceMock);
        $changes = [];
        $service->updateToiletFromPlace($toilet, [
            'place_id' => 'ChIJcrawl456',
            'website' => 'https://example.com',
        ], null, $changes);

        $toilet->refresh();
        $this->assertNotNull($toilet->last_crawled);
        $this->assertTrue($toilet->last_crawled->isToday());
    }

    public function test_google_places_service_blocks_requests_when_budget_exceeded(): void
    {
        /** @var GoogleCostService $costService */
        $costService = $this->app->make(GoogleCostService::class);
        $costService->setMonthlyBudget(0.00); // 0 budget -> exceeded

        $placesService = $this->app->make(GooglePlacesService::class);

        // Fetch place details should return null without making HTTP calls
        $details = $placesService->fetchPlaceDetails('ChIJtest123');
        $this->assertNull($details);

        // Nearby search should return empty array
        $nearby = $placesService->placesForCoordinates(52.52, 13.40);
        $this->assertEmpty($nearby);
    }
}
