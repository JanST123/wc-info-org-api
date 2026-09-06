<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\GoogleApiLog;
use App\Services\GoogleCostService;
use App\Services\MailService;
use Carbon\Carbon;
use Tests\TestCase;

class GoogleCostServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GoogleApiLog::truncate();
        AppSetting::truncate();
    }

    public function test_logs_api_calls_and_calculates_monthly_cost(): void
    {
        /** @var GoogleCostService $service */
        $service = $this->app->make(GoogleCostService::class);

        $service->logApiCall(GoogleCostService::SERVICE_PLACES_NEARBY, 'https://places.googleapis.com/v1/places:searchNearby', null, 200, ['lat' => 52.52, 'lon' => 13.40]);
        $service->logApiCall(GoogleCostService::SERVICE_PLACES_DETAILS, 'https://places.googleapis.com/v1/places/ChIJ123', null, 200, ['place_id' => 'ChIJ123']);
        $service->logApiCall(GoogleCostService::SERVICE_CUSTOM_SEARCH, 'https://www.googleapis.com/customsearch/v1', null, 200, ['q' => 'wc berlin']);

        $this->assertEquals(3, GoogleApiLog::count());

        $expectedCost = GoogleCostService::PRICING[GoogleCostService::SERVICE_PLACES_NEARBY]
            + GoogleCostService::PRICING[GoogleCostService::SERVICE_PLACES_DETAILS]
            + GoogleCostService::PRICING[GoogleCostService::SERVICE_CUSTOM_SEARCH];

        $this->assertEquals($expectedCost, $service->getCurrentMonthCost());
    }

    public function test_budget_exceeded_blocks_api_calls_and_sends_email(): void
    {
        $mailMock = $this->createMock(MailService::class);
        $mailMock->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->stringContains('Google API Budget Exceeded'),
                $this->anything(),
                $this->isTrue()
            );

        $this->app->instance(MailService::class, $mailMock);

        /** @var GoogleCostService $service */
        $service = $this->app->make(GoogleCostService::class);
        $service->setMonthlyBudget(0.05); // Very low budget: $0.05

        $this->assertTrue($service->hasBudget());

        // Log two nearby calls: 2 * $0.032 = $0.064 (exceeds $0.05)
        $service->logApiCall(GoogleCostService::SERVICE_PLACES_NEARBY);
        $service->logApiCall(GoogleCostService::SERVICE_PLACES_NEARBY);

        $this->assertFalse($service->hasBudget());

        // Logging another call should not trigger another email (alert already sent for this month)
        $service->logApiCall(GoogleCostService::SERVICE_CUSTOM_SEARCH);
    }

    public function test_admin_costs_dashboard_and_budget_update(): void
    {
        /** @var GoogleCostService $service */
        $service = $this->app->make(GoogleCostService::class);
        $service->logApiCall(GoogleCostService::SERVICE_PLACES_NEARBY, 'https://places.googleapis.com/v1/places:searchNearby', null, 200, ['test' => 1]);

        $response = $this->withSession(['admin_logged_in' => true])
            ->get('/admin/costs');

        $response->assertStatus(200);
        $response->assertSee('Google API Cost Control');
        $response->assertSee('Places Nearby Search');

        // Update budget via POST
        $postResponse = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/costs/budget', ['budget' => '75.50']);

        $postResponse->assertRedirect(route('admin.costs'));
        $postResponse->assertSessionHas('success');

        $this->assertEquals(75.50, $service->getMonthlyBudget());
    }
}
