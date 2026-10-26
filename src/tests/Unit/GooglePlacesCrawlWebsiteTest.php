<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GoogleCostService;
use App\Services\GoogleNearbyCacheService;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GooglePlacesCrawlWebsiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.default' => 'null']);
    }

    public function test_crawl_website_uses_gemini_url_context_and_google_search_grounding(): void
    {
        config([
            'wcinfo.google.api_key' => 'test-google-key',
            'wcinfo.gemini.api_key' => 'test-gemini-key',
            'wcinfo.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => function ($request) {
                $this->assertStringContainsString('gemini-3.8-flash:generateContent', $request->url());
                $this->assertEquals('test-gemini-key', $request->header('x-goog-api-key')[0] ?? '');

                $body = json_decode($request->body(), true);
                $this->assertNotEmpty($body['tools']);
                $this->assertArrayHasKey('url_context', $body['tools'][0]);
                $this->assertArrayHasKey('google_search', $body['tools'][1]);

                return Http::response([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => json_encode([
                                            'has_toilet' => true,
                                            'is_gender_separated' => true,
                                            'is_wheelchair_accessible' => true,
                                            'has_changing_table' => false,
                                            'contact_email' => 'kontakt@cafe-beispiel.de',
                                            'result_count' => 2,
                                            'snippets' => [
                                                'Unsere Gäste-WCs (Damen/Herren) sind barrierefrei zugänglich.',
                                                'Kontakt: kontakt@cafe-beispiel.de',
                                            ],
                                        ]),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);
        $costMock->expects($this->once())
            ->method('logApiCall')
            ->with(
                GoogleCostService::SERVICE_CUSTOM_SEARCH,
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent',
                GoogleCostService::COST_CUSTOM_SEARCH_USD,
                200,
                ['url' => 'https://www.cafe-beispiel.de', 'term' => 'toilet']
            );

        $cacheMock = $this->createMock(GoogleNearbyCacheService::class);

        $service = new GooglePlacesService($costMock, $cacheMock);
        $result = $service->crawlWebsite('https://www.cafe-beispiel.de', 'toilet');

        $this->assertSame('mwd', $result['toiletType']);
        $this->assertSame('kontakt@cafe-beispiel.de', $result['contactEmail']);
        $this->assertSame(2, $result['resultCount']);
    }

    public function test_crawl_website_rejects_invalid_url(): void
    {
        config([
            'wcinfo.google.api_key' => 'test-google-key',
            'wcinfo.gemini.api_key' => 'test-gemini-key',
        ]);

        $costMock = $this->createMock(GoogleCostService::class);
        $costMock->method('hasBudget')->willReturn(true);
        $cacheMock = $this->createMock(GoogleNearbyCacheService::class);

        $service = new GooglePlacesService($costMock, $cacheMock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported url pattern');

        $service->crawlWebsite('not-a-valid-url', 'toilet');
    }
}
