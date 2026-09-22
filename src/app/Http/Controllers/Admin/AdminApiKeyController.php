<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\RateLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminApiKeyController extends Controller
{
    public function __construct(
        private readonly RateLimitService $rateLimitService
    ) {}

    /**
     * Display the API keys management and rate-limit monitoring dashboard.
     */
    public function index(): View
    {
        $apiKeys = ApiKey::orderBy('id')->get();
        $metrics = [];

        foreach ($apiKeys as $key) {
            $metrics[$key->id] = $this->rateLimitService->getKeyMetrics($key);
        }

        $blockedIps = $this->rateLimitService->getActiveBlockedIps();

        return view('admin.api_keys.index', [
            'apiKeys' => $apiKeys,
            'metrics' => $metrics,
            'blockedIps' => $blockedIps,
        ]);
    }

    /**
     * Store a new API key.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:10000',
            'rate_limit_penalty_period' => 'nullable|integer|min:1|max:86400',
            'rate_limit_block_duration' => 'nullable|integer|min:1|max:86400',
            'global_rate_limit_per_minute' => 'nullable|integer|min:1|max:100000',
        ]);

        $prefix = match (strtolower($validated['name'])) {
            'ios', 'ios app' => 'wc_ios_',
            'android', 'android app' => 'wc_and_',
            'web', 'web app' => 'wc_web_',
            default => 'wc_key_',
        };

        $apiKey = ApiKey::create([
            'key' => ApiKey::generateKey($prefix),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
            'rate_limit_per_minute' => $validated['rate_limit_per_minute'] ?? 40,
            'rate_limit_penalty_period' => $validated['rate_limit_penalty_period'] ?? 300,
            'rate_limit_block_duration' => $validated['rate_limit_block_duration'] ?? 120,
            'global_rate_limit_per_minute' => $validated['global_rate_limit_per_minute'] ?? 800,
        ]);

        return redirect()->route('admin.api_keys.index')->with('success', "API Key '{$apiKey->name}' created successfully.");
    }

    /**
     * Update an existing API key's configuration.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $apiKey = ApiKey::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'rate_limit_per_minute' => 'required|integer|min:1|max:10000',
            'rate_limit_penalty_period' => 'required|integer|min:1|max:86400',
            'rate_limit_block_duration' => 'required|integer|min:1|max:86400',
            'global_rate_limit_per_minute' => 'required|integer|min:1|max:100000',
        ]);

        $apiKey->update($validated);

        return redirect()->route('admin.api_keys.index')->with('success', "API Key '{$apiKey->name}' updated successfully.");
    }

    /**
     * Toggle active/inactive status of an API key.
     */
    public function toggle(int $id): RedirectResponse
    {
        $apiKey = ApiKey::findOrFail($id);
        $apiKey->is_active = ! $apiKey->is_active;
        $apiKey->save();

        $status = $apiKey->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.api_keys.index')->with('success', "API Key '{$apiKey->name}' has been {$status}.");
    }

    /**
     * Regenerate the secret token of an API key.
     */
    public function regenerate(int $id): RedirectResponse
    {
        $apiKey = ApiKey::findOrFail($id);

        $prefix = match (strtolower($apiKey->name)) {
            'ios', 'ios app' => 'wc_ios_',
            'android', 'android app' => 'wc_and_',
            'web', 'web app' => 'wc_web_',
            default => 'wc_key_',
        };

        $apiKey->key = ApiKey::generateKey($prefix);
        $apiKey->save();

        return redirect()->route('admin.api_keys.index')->with('success', "Token for API Key '{$apiKey->name}' regenerated successfully. Make sure to update the client application!");
    }

    /**
     * Manually unblock an IP.
     */
    public function unblock(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'api_key_id' => 'required|integer',
            'ip' => 'required|string',
        ]);

        $this->rateLimitService->unblockIp((int) $validated['api_key_id'], $validated['ip']);

        return redirect()->route('admin.api_keys.index')->with('success', "IP {$validated['ip']} was unblocked successfully.");
    }
}
