<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if (empty($origin)) {
            return $response;
        }

        $allowedOrigins = $this->getAllowedOrigins();

        if ($this->isOriginAllowed($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Api-Key, X-Requested-With, Accept, Origin, x-xsrf-token');
            $response->headers->set('Access-Control-Max-Age', '86400');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }

    /**
     * @return array<int, string>
     */
    private function getAllowedOrigins(): array
    {
        $configValue = config('wcinfo.cors.allowed_origins') ?? config('cors.allowed_origins') ?? env('CORS_ALLOWED_ORIGINS', '*');

        if (is_array($configValue)) {
            return $configValue;
        }

        $origins = explode(',', (string) $configValue);

        return array_values(array_filter(array_map('trim', $origins)));
    }

    /**
     * @param  array<int, string>  $allowedOrigins
     */
    private function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        foreach ($allowedOrigins as $allowed) {
            if ($allowed === $origin || Str::is($allowed, $origin)) {
                return true;
            }
        }

        return false;
    }
}
