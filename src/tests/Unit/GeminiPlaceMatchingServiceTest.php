<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletProperty;
use App\Services\GeminiPlaceMatchingService;
use App\Services\GoogleCostService;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiPlaceMatchingServiceTest extends TestCase
{
    private array $createdToiletIds = [];
    private array $createdPlaceIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_suggest_place_fails_when_api_key_missing(): void
    {
        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);

        $placesMock = $this->createMock(GooglePlacesService::class);

        $service = new GeminiPlaceMatchingService(
            $placesMock,
            $costMock,
            geminiApiKey: ''
        );

        $toilet = Toilet::create([
            'name' => 'Test Toilet No Key',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $result = $service->suggestPlace($toilet);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Gemini API key is not configured', $result['error']);
    }

    public function test_suggest_place_fails_when_budget_exceeded(): void
    {
        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(false);

        $placesMock = $this->createMock(GooglePlacesService::class);

        $service = new GeminiPlaceMatchingService(
            $placesMock,
            $costMock,
            geminiApiKey: 'fake-api-key'
        );

        $toilet = Toilet::create([
            'name' => 'Test Toilet Budget Exceeded',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $result = $service->suggestPlace($toilet);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('budget exceeded', $result['error']);
    }

    public function test_suggest_place_auto_selects_nearby_public_bathroom(): void
    {
        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);

        $placesMock = $this->createMock(GooglePlacesService::class);
        $placesMock->expects($this->once())
            ->method('nearbySearchRaw')
            ->willReturn([
                [
                    'id' => 'ChIJbathroom999',
                    'displayName' => ['text' => 'Public Toilet City Hall'],
                    'formattedAddress' => 'Rathausplatz 1',
                    'types' => ['public_bathroom'],
                    'location' => ['latitude' => 52.5200, 'longitude' => 13.4050],
                ],
            ]);

        $service = new GeminiPlaceMatchingService(
            $placesMock,
            $costMock,
            geminiApiKey: 'fake-api-key'
        );

        $toilet = Toilet::create([
            'name' => 'WC am Rathaus',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;
        $this->createdPlaceIds[] = 'ChIJbathroom999';

        $result = $service->suggestPlace($toilet);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['matched']);
        $this->assertEquals('ChIJbathroom999', $result['place']['place_id']);
        $this->assertEquals('high', $result['confidence']);
        $this->assertEquals('nearby_public_bathroom', $result['source']);
    }

    public function test_suggest_place_uses_gemini_to_match_nearby_place(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'match_found' => true,
                                        'matched_place_id' => 'ChIJstation888',
                                        'confidence' => 'high',
                                        'reasoning' => 'The toilet name "WC Hauptbahnhof" directly matches Central Station.',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);

        $placesMock = $this->createMock(GooglePlacesService::class);
        $placesMock->expects($this->once())
            ->method('nearbySearchRaw')
            ->willReturn([
                [
                    'id' => 'ChIJstation888',
                    'displayName' => ['text' => 'Berlin Hauptbahnhof'],
                    'formattedAddress' => 'Europaplatz 1, 10557 Berlin',
                    'types' => ['train_station', 'transit_station'],
                    'location' => ['latitude' => 52.5255, 'longitude' => 13.3695],
                ],
            ]);

        $service = new GeminiPlaceMatchingService(
            $placesMock,
            $costMock,
            geminiApiKey: 'fake-api-key'
        );

        $toilet = Toilet::create([
            'name' => 'WC Hauptbahnhof',
            'lat' => 52.5255,
            'lon' => 13.3695,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;
        $this->createdPlaceIds[] = 'ChIJstation888';

        $result = $service->suggestPlace($toilet);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['matched']);
        $this->assertEquals('ChIJstation888', $result['place']['place_id']);
        $this->assertTrue($result['place']['is_public_accessible']); // train_station is in config public_accessible_types
        $this->assertEquals('high', $result['confidence']);
        $this->assertEquals('nearby_gemini', $result['source']);
    }

    public function test_suggest_place_falls_back_to_address_search(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'match_found' => true,
                                        'matched_place_id' => 'ChIJmall555',
                                        'confidence' => 'medium',
                                        'reasoning' => 'Matched shopping mall from address query.',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);

        $placesMock = $this->createMock(GooglePlacesService::class);
        $placesMock->expects($this->once())
            ->method('nearbySearchRaw')
            ->willReturn([]); // No nearby places found

        $placesMock->expects($this->once())
            ->method('textSearchRaw')
            ->with('Leipziger Platz 12, 10117 Berlin')
            ->willReturn([
                [
                    'id' => 'ChIJmall555',
                    'displayName' => ['text' => 'Mall of Berlin'],
                    'formattedAddress' => 'Leipziger Platz 12, 10117 Berlin',
                    'types' => ['shopping_mall'],
                    'location' => ['latitude' => 52.5108, 'longitude' => 13.3812],
                ],
            ]);

        $service = new GeminiPlaceMatchingService(
            $placesMock,
            $costMock,
            geminiApiKey: 'fake-api-key'
        );

        $toilet = Toilet::create([
            'name' => 'WC Mall',
            'lat' => 52.5108,
            'lon' => 13.3812,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;
        $this->createdPlaceIds[] = 'ChIJmall555';

        ToiletProperty::create([
            'fk_toiletId' => $toilet->id,
            'type' => 'address',
            'value' => 'Leipziger Platz 12, 10117 Berlin',
        ]);

        $result = $service->suggestPlace($toilet);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['matched']);
        $this->assertEquals('ChIJmall555', $result['place']['place_id']);
        $this->assertTrue($result['place']['is_public_accessible']); // shopping_mall in public_accessible_types
        $this->assertEquals('address_gemini', $result['source']);
    }

    public function test_suggest_place_falls_back_to_website_crawl(): void
    {
        // 1. First Gemini call (nearby places) returns match_found = false
        // 2. Second Gemini call (website crawl confirmed candidates) returns match_found = true
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => json_encode([
                                            'match_found' => false,
                                            'matched_place_id' => null,
                                            'confidence' => 'low',
                                            'reasoning' => 'Name alone does not match generic cafe name.',
                                        ]),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => json_encode([
                                            'match_found' => true,
                                            'matched_place_id' => 'ChIJresort111',
                                            'confidence' => 'high',
                                            'reasoning' => 'Place website confirms public guest toilets on premises.',
                                        ]),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);

        $placesMock = $this->createMock(GooglePlacesService::class);
        $placesMock->expects($this->once())
            ->method('nearbySearchRaw')
            ->willReturn([
                [
                    'id' => 'ChIJresort111',
                    'displayName' => ['text' => 'Holiday Resort & Camp'],
                    'formattedAddress' => 'Lake Road 1',
                    'types' => ['campground', 'lodging'],
                    'websiteUri' => 'https://www.holiday-resort.de/',
                    'location' => ['latitude' => 52.2000, 'longitude' => 13.7000],
                ],
            ]);

        $placesMock->expects($this->once())
            ->method('crawlWebsite')
            ->with('https://www.holiday-resort.de/', 'toilet')
            ->willReturn([
                'toiletType' => 'mw',
                'contactEmail' => 'info@holiday-resort.de',
                'resultCount' => 3,
            ]);

        $service = new GeminiPlaceMatchingService(
            $placesMock,
            $costMock,
            geminiApiKey: 'fake-api-key'
        );

        $toilet = Toilet::create([
            'name' => 'WC am See',
            'lat' => 52.2000,
            'lon' => 13.7000,
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;
        $this->createdPlaceIds[] = 'ChIJresort111';

        $result = $service->suggestPlace($toilet);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['matched']);
        $this->assertEquals('ChIJresort111', $result['place']['place_id']);
        $this->assertEquals('website_crawl', $result['source']);
        $this->assertEquals('high', $result['confidence']);
    }
}
