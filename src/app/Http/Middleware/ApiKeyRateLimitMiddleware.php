<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\RateLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyRateLimitMiddleware
{
    public function __construct(
        private readonly RateLimitService $rateLimitService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractApiKey($request);

        if (empty($token)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API key is missing. Provide via X-Api-Key header, Bearer token, or api_key query parameter.',
            ], 401);
        }

        $apiKey = ApiKey::where('key', $token)->first();

        if (! $apiKey || ! $apiKey->is_active) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or inactive API key.',
            ], 401);
        }

        $ip = $request->ip() ?? '127.0.0.1';
        $rateLimit = $this->rateLimitService->check($apiKey, $ip);

        if (! $rateLimit['allowed']) {
            $response = response()->json([
                'error' => 'Too Many Requests',
                'message' => $rateLimit['reason'] ?? 'Rate limit exceeded.',
            ], $rateLimit['status']);

            if (isset($rateLimit['retry_after'])) {
                $response->headers->set('Retry-After', (string) $rateLimit['retry_after']);
            }
            $response->headers->set('X-RateLimit-Limit', (string) $rateLimit['limit']);
            $response->headers->set('X-RateLimit-Remaining', (string) $rateLimit['remaining']);
            $response->headers->set('X-RateLimit-Reset', (string) $rateLimit['reset']);

            return $response;
        }

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $rateLimit['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $rateLimit['remaining']);
        $response->headers->set('X-RateLimit-Reset', (string) $rateLimit['reset']);

        return $response;
    }

    /**
     * Extract API key token from headers or query parameters.
     */
    private function extractApiKey(Request $request): ?string
    {
        // 1. X-Api-Key header
        $headerKey = $request->header('X-Api-Key');
        if (! empty($headerKey)) {
            return trim($headerKey);
        }

        // 2. Authorization: Bearer <key>
        $bearerToken = $request->bearerToken();
        if (! empty($bearerToken)) {
            return trim($bearerToken);
        }

        // 3. api_key query string parameter
        $queryKey = $request->query('api_key');
        if (! empty($queryKey) && is_string($queryKey)) {
            return trim($queryKey);
        }

        return null;
    }
}
