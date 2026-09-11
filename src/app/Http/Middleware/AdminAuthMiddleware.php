<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\AdminAuthController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('admin_logged_in')) {
            return $next($request);
        }

        $rememberToken = $request->cookie(AdminAuthController::REMEMBER_COOKIE_NAME);
        if (AdminAuthController::isValidRememberToken($rememberToken)) {
            $request->session()->put('admin_logged_in', true);

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->guest(route('admin.login'));
    }
}
