<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateJsonBodyMiddleware
{
    /**
     * Handle an incoming request and ensure JSON payloads are syntactically valid.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $contentType = $request->header('Content-Type', '');
        $isJsonContentType = str_contains(strtolower($contentType), 'application/json')
            || str_contains(strtolower($contentType), '+json');

        if ($isJsonContentType) {
            $content = $request->getContent();

            if ($content !== '' && trim($content) !== '') {
                if (! json_validate($content)) {
                    return response()->json([
                        'error' => 'Malformed JSON payload: '.json_last_error_msg(),
                    ], 400);
                }
            }
        }

        return $next($request);
    }
}
