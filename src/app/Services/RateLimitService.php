<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RateLimitService
{
    public function __construct(
        private readonly MailService $mailService
    ) {}

    /**
     * Check and process rate limits for the given API key and client IP.
     *
     * @return array{
     *     allowed: bool,
     *     status: int,
     *     reason?: string,
     *     retry_after?: int,
     *     limit: int,
     *     remaining: int,
     *     reset: int
     * }
     */
    public function check(ApiKey $apiKey, string $ip): array
    {
        $now = time();
        $minuteBucket = (int) floor($now / 60);
        $resetSeconds = 60 - ($now % 60);

        // 1. Check if IP is currently hard-blocked
        $blockKey = "rl:blocked:{$apiKey->id}:{$ip}";
        $blockedUntil = Cache::get($blockKey);

        if ($blockedUntil && $blockedUntil > $now) {
            $retryAfter = (int) ($blockedUntil - $now);

            return [
                'allowed' => false,
                'status' => 429,
                'reason' => 'Rate limit exceeded. Your IP has been temporarily blocked.',
                'retry_after' => $retryAfter,
                'limit' => $apiKey->rate_limit_per_minute,
                'remaining' => 0,
                'reset' => $retryAfter,
            ];
        }

        // 2. Check Global Safety Ceiling (all IPs for this key)
        $globalMinuteKey = "rl:global:{$apiKey->id}:{$minuteBucket}";
        $globalCount = (int) Cache::get($globalMinuteKey, 0);

        if ($globalCount >= $apiKey->global_rate_limit_per_minute) {
            $this->triggerGlobalLimitAlert($apiKey, $globalCount);

            return [
                'allowed' => false,
                'status' => 429,
                'reason' => 'Global API rate limit exceeded. Please try again later.',
                'retry_after' => $resetSeconds,
                'limit' => $apiKey->global_rate_limit_per_minute,
                'remaining' => 0,
                'reset' => $resetSeconds,
            ];
        }

        // 3. Determine if IP is in penalty / slowdown phase
        $penaltyKey = "rl:penalty:{$apiKey->id}:{$ip}";
        $penaltyUntil = Cache::get($penaltyKey);
        $isPenalized = ($penaltyUntil && $penaltyUntil > $now);

        // In penalty phase: max 10 req/min (or max(1, floor(limit / 4))), plus 1.0s sleep delay
        $effectiveLimit = $isPenalized
            ? min(10, max(1, (int) floor($apiKey->rate_limit_per_minute / 4)))
            : $apiKey->rate_limit_per_minute;

        if ($isPenalized) {
            // Artificial delay during penalty slowdown
            usleep(1000000); // 1.0 second
        }

        // 4. Check IP request count for current minute
        $ipMinuteKey = "rl:ip:{$apiKey->id}:{$ip}:{$minuteBucket}";
        $ipCount = (int) Cache::get($ipMinuteKey, 0);

        if ($ipCount >= $effectiveLimit) {
            // Trigger Hard Block
            $blockDuration = $apiKey->rate_limit_block_duration; // default 120s
            $penaltyDuration = $apiKey->rate_limit_penalty_period; // default 300s
            $blockExpiresAt = $now + $blockDuration;
            $penaltyExpiresAt = $now + $blockDuration + $penaltyDuration;

            Cache::put($blockKey, $blockExpiresAt, $blockDuration);
            Cache::put($penaltyKey, $penaltyExpiresAt, $blockDuration + $penaltyDuration);

            // Register in tracking set for admin dashboard
            $this->registerBlockedIp($apiKey->id, $ip, $blockExpiresAt, $penaltyExpiresAt);

            Log::warning("API Key [{$apiKey->name}] IP [{$ip}] hard-blocked for {$blockDuration}s after {$ipCount} reqs");

            return [
                'allowed' => false,
                'status' => 429,
                'reason' => "Rate limit exceeded. Your IP has been temporarily blocked for {$blockDuration} seconds.",
                'retry_after' => $blockDuration,
                'limit' => $effectiveLimit,
                'remaining' => 0,
                'reset' => $blockDuration,
            ];
        }

        // 5. Increment counters
        $this->incrementCacheKey($ipMinuteKey, 120);
        $this->incrementCacheKey($globalMinuteKey, 120);

        $newIpCount = $ipCount + 1;
        $remaining = max(0, $effectiveLimit - $newIpCount);

        return [
            'allowed' => true,
            'status' => 200,
            'limit' => $effectiveLimit,
            'remaining' => $remaining,
            'reset' => $resetSeconds,
        ];
    }

    /**
     * Trigger global limit email alert (throttled to 1 alert every 15 minutes per key).
     */
    private function triggerGlobalLimitAlert(ApiKey $apiKey, int $currentCount): void
    {
        $alertCacheKey = "rl:alert_sent:{$apiKey->id}";
        if (Cache::has($alertCacheKey)) {
            return;
        }

        Cache::put($alertCacheKey, true, 900); // 15 minutes TTL

        try {
            $senderMail = config('wcinfo.sender_mail');
            if ($senderMail) {
                $subject = "ALERT: Global rate limit hit for API Key {$apiKey->name}";
                $body = "Warning: The API key '{$apiKey->name}' (ID: {$apiKey->id}) has hit its global safety ceiling of {$apiKey->global_rate_limit_per_minute} req/min.\n"
                    ."Current requests in the last minute: {$currentCount}\n"
                    ."Timestamp: ".date('Y-m-d H:i:s')."\n\n"
                    ."All further requests using this API key across all IPs will be throttled until the minute window resets.\n"
                    ."Please check the admin panel for potential scraping or botnet activity: ".url('/admin/api-keys');

                $this->mailService->send($senderMail, $subject, $body, false);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send global rate limit alert email: '.$e->getMessage());
        }
    }

    /**
     * Atomically increment a cache key and set TTL if new.
     */
    private function incrementCacheKey(string $key, int $ttlSeconds): int
    {
        if (! Cache::has($key)) {
            Cache::put($key, 1, $ttlSeconds);

            return 1;
        }

        return (int) Cache::increment($key);
    }

    /**
     * Register blocked/penalized IP in index for Admin UI.
     */
    private function registerBlockedIp(int $keyId, string $ip, int $blockExpiresAt, int $penaltyExpiresAt): void
    {
        $indexKey = 'rl:active_blocks_index';
        $records = Cache::get($indexKey, []);
        $entryKey = "{$keyId}_{$ip}";
        $records[$entryKey] = [
            'api_key_id' => $keyId,
            'ip' => $ip,
            'blocked_until' => $blockExpiresAt,
            'penalty_until' => $penaltyExpiresAt,
            'created_at' => time(),
        ];
        // Clean up expired entries while saving
        $now = time();
        $records = array_filter($records, fn ($r) => $r['penalty_until'] > $now);
        Cache::put($indexKey, $records, 86400);
    }

    /**
     * Get active blocked and penalized IPs for Admin UI.
     *
     * @return array<int, array{
     *     api_key_id: int,
     *     api_key_name: string,
     *     ip: string,
     *     is_blocked: bool,
     *     is_penalized: bool,
     *     blocked_remaining_seconds: int,
     *     penalty_remaining_seconds: int,
     *     created_at: int
     * }>
     */
    public function getActiveBlockedIps(): array
    {
        $indexKey = 'rl:active_blocks_index';
        $records = Cache::get($indexKey, []);
        $now = time();
        $keys = ApiKey::all()->keyBy('id');

        $active = [];
        $cleaned = [];

        foreach ($records as $entryKey => $item) {
            if ($item['penalty_until'] <= $now) {
                continue;
            }
            $cleaned[$entryKey] = $item;

            $isBlocked = $item['blocked_until'] > $now;
            $isPenalized = ! $isBlocked && ($item['penalty_until'] > $now);
            $keyName = $keys->get($item['api_key_id'])?->name ?? "Key #{$item['api_key_id']}";

            $active[] = [
                'api_key_id' => $item['api_key_id'],
                'api_key_name' => $keyName,
                'ip' => $item['ip'],
                'is_blocked' => $isBlocked,
                'is_penalized' => $isPenalized,
                'blocked_remaining_seconds' => max(0, $item['blocked_until'] - $now),
                'penalty_remaining_seconds' => max(0, $item['penalty_until'] - $now),
                'created_at' => $item['created_at'],
            ];
        }

        if (count($cleaned) !== count($records)) {
            Cache::put($indexKey, $cleaned, 86400);
        }

        return $active;
    }

    /**
     * Manually unblock an IP.
     */
    public function unblockIp(int $keyId, string $ip): void
    {
        Cache::forget("rl:blocked:{$keyId}:{$ip}");
        Cache::forget("rl:penalty:{$keyId}:{$ip}");

        $indexKey = 'rl:active_blocks_index';
        $records = Cache::get($indexKey, []);
        unset($records["{$keyId}_{$ip}"]);
        Cache::put($indexKey, $records, 86400);
    }

    /**
     * Get live metrics for an API key for the Admin UI.
     *
     * @return array{
     *     current_req_per_min: int,
     *     global_limit: int,
     *     usage_percent: float,
     *     remaining: int
     * }
     */
    public function getKeyMetrics(ApiKey $apiKey): array
    {
        $now = time();
        $minuteBucket = (int) floor($now / 60);
        $globalMinuteKey = "rl:global:{$apiKey->id}:{$minuteBucket}";
        $currentCount = (int) Cache::get($globalMinuteKey, 0);
        $limit = $apiKey->global_rate_limit_per_minute;
        $remaining = max(0, $limit - $currentCount);
        $percent = $limit > 0 ? round(($currentCount / $limit) * 100, 1) : 0.0;

        return [
            'current_req_per_min' => $currentCount,
            'global_limit' => $limit,
            'usage_percent' => $percent,
            'remaining' => $remaining,
        ];
    }
}
