<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\GoogleApiLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class GoogleCostService
{
    public const SERVICE_PLACES_NEARBY = 'places_nearby';
    public const SERVICE_PLACES_DETAILS = 'places_details';
    public const SERVICE_CUSTOM_SEARCH = 'custom_search';
    public const SERVICE_GEOCODING = 'geocoding';

    public const COST_PLACES_NEARBY_USD = 0.032;
    public const COST_PLACES_DETAILS_USD = 0.017;
    public const COST_CUSTOM_SEARCH_USD = 0.005;
    public const COST_GEOCODING_USD = 0.005;

    public const PRICING = [
        self::SERVICE_PLACES_NEARBY => self::COST_PLACES_NEARBY_USD,
        self::SERVICE_PLACES_DETAILS => self::COST_PLACES_DETAILS_USD,
        self::SERVICE_CUSTOM_SEARCH => self::COST_CUSTOM_SEARCH_USD,
        self::SERVICE_GEOCODING => self::COST_GEOCODING_USD,
    ];

    public function __construct(
        private ?MailService $mail = null,
    ) {
        $this->mail = $this->mail ?? app(MailService::class);
    }

    /**
     * Get the configured monthly budget in USD.
     */
    public function getMonthlyBudget(): float
    {
        $setting = AppSetting::get('google_monthly_budget');

        if ($setting !== null && is_numeric($setting)) {
            return (float) $setting;
        }

        return (float) config('wcinfo.google.monthly_budget', 20.0);
    }

    /**
     * Update the configured monthly budget in USD.
     */
    public function setMonthlyBudget(float $budget): void
    {
        AppSetting::set('google_monthly_budget', max(0.0, $budget));
    }

    /**
     * Get total costs incurred in the current calendar month in USD.
     */
    public function getCurrentMonthCost(): float
    {
        $now = Carbon::now();

        return (float) GoogleApiLog::forMonth($now->year, $now->month)->sum('cost_usd');
    }

    /**
     * Check if budget is available for new Google API calls.
     */
    public function hasBudget(): bool
    {
        $budget = $this->getMonthlyBudget();

        if ($budget <= 0.0) {
            return false;
        }

        return $this->getCurrentMonthCost() < $budget;
    }

    /**
     * Record a Google API call with its estimated cost.
     */
    public function logApiCall(
        string $service,
        string $endpoint = '',
        ?float $costUsd = null,
        int $statusCode = 200,
        array $context = []
    ): GoogleApiLog {
        $cost = $costUsd ?? (self::PRICING[$service] ?? 0.0);

        $log = GoogleApiLog::create([
            'service' => $service,
            'endpoint' => $endpoint,
            'cost_usd' => $cost,
            'status_code' => $statusCode,
            'context' => ! empty($context) ? $context : null,
            'created_at' => now(),
        ]);

        if (! $this->hasBudget()) {
            $this->checkBudgetAndNotify();
        }

        return $log;
    }

    /**
     * Check if budget is exceeded and send an alert notification if not yet sent this month.
     */
    public function checkBudgetAndNotify(): void
    {
        if ($this->hasBudget()) {
            return;
        }

        $currentMonthKey = Carbon::now()->format('Y-m');
        $lastAlertMonth = AppSetting::get('google_budget_alert_sent_month');

        if ($lastAlertMonth === $currentMonthKey) {
            return; // Already notified this month
        }

        $currentCost = $this->getCurrentMonthCost();
        $budget = $this->getMonthlyBudget();
        $recipient = (string) config('wcinfo.admin_notification_email', 'hallo@wc-info.de');

        $body = "<h2>🚨 Google API Monthly Budget Limit Exceeded</h2>\n";
        $body .= "<p>The configured monthly Google API budget for WC-Info has been reached or exceeded.</p>\n";
        $body .= "<table style='border-collapse: collapse; margin: 15px 0;'>\n";
        $body .= "<tr><td style='padding: 6px 12px; font-weight: bold;'>Month:</td><td style='padding: 6px 12px;'>".e($currentMonthKey)."</td></tr>\n";
        $body .= "<tr><td style='padding: 6px 12px; font-weight: bold;'>Monthly Budget:</td><td style='padding: 6px 12px;'>$".number_format($budget, 2)." USD</td></tr>\n";
        $body .= "<tr><td style='padding: 6px 12px; font-weight: bold;'>Current Month Cost:</td><td style='padding: 6px 12px; color: #dc2626; font-weight: bold;'>$".number_format($currentCost, 2)." USD</td></tr>\n";
        $body .= "</table>\n";
        $body .= "<p><strong>Actions Taken:</strong> All outgoing calls to Google APIs (Places Nearby Search, Place Details, Website Crawls, Geocoding) are now <strong>blocked</strong> automatically to protect against cloud cost overruns.</p>\n";
        $body .= "<p>To resume Google API services, adjust the budget limit in the <a href='".url('/admin/costs')."'>Admin Panel</a>.</p>\n";

        try {
            $this->mail->send(
                $recipient,
                "🚨 Alert: Google API Budget Exceeded ($".number_format($currentCost, 2)." / $".number_format($budget, 2).")",
                $body,
                true
            );
            AppSetting::set('google_budget_alert_sent_month', $currentMonthKey);
            Log::warning('Google API budget exceeded alert sent', [
                'current_cost' => $currentCost,
                'budget' => $budget,
                'month' => $currentMonthKey,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send Google API budget alert email', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get aggregated monthly statistics.
     */
    public function getMonthlyStats(?int $year = null, ?int $month = null): array
    {
        $year = $year ?? (int) date('Y');
        $month = $month ?? (int) date('n');

        $budget = $this->getMonthlyBudget();
        $logs = GoogleApiLog::forMonth($year, $month)->get();

        $totalCost = (float) $logs->sum('cost_usd');
        $totalRequests = $logs->count();

        $services = [
            self::SERVICE_PLACES_NEARBY => [
                'name' => 'Places Nearby Search',
                'count' => 0,
                'cost' => 0.0,
                'unit_cost' => self::COST_PLACES_NEARBY_USD,
            ],
            self::SERVICE_PLACES_DETAILS => [
                'name' => 'Place Details',
                'count' => 0,
                'cost' => 0.0,
                'unit_cost' => self::COST_PLACES_DETAILS_USD,
            ],
            self::SERVICE_CUSTOM_SEARCH => [
                'name' => 'Website Crawl (Custom Search)',
                'count' => 0,
                'cost' => 0.0,
                'unit_cost' => self::COST_CUSTOM_SEARCH_USD,
            ],
            self::SERVICE_GEOCODING => [
                'name' => 'Geocoding (Photo GPS)',
                'count' => 0,
                'cost' => 0.0,
                'unit_cost' => self::COST_GEOCODING_USD,
            ],
        ];

        foreach ($logs as $log) {
            $svc = $log->service;
            if (isset($services[$svc])) {
                $services[$svc]['count']++;
                $services[$svc]['cost'] += (float) $log->cost_usd;
            }
        }

        $percentageUsed = $budget > 0 ? min(100.0, round(($totalCost / $budget) * 100, 1)) : 100.0;
        $remainingBudget = max(0.0, $budget - $totalCost);

        return [
            'year' => $year,
            'month' => $month,
            'budget' => $budget,
            'total_cost' => $totalCost,
            'total_requests' => $totalRequests,
            'remaining_budget' => $remainingBudget,
            'percentage_used' => $percentageUsed,
            'is_exceeded' => $totalCost >= $budget,
            'services' => $services,
        ];
    }

    /**
     * Get recent Google API logs.
     *
     * @return Collection<int, GoogleApiLog>
     */
    public function getRecentLogs(int $limit = 50): Collection
    {
        return GoogleApiLog::orderByDesc('id')->limit($limit)->get();
    }
}
