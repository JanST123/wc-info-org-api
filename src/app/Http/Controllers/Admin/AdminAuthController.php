<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public const REMEMBER_COOKIE_NAME = 'admin_remember';
    public const REMEMBER_DURATION_MINUTES = 60 * 24 * 30; // 30 days

    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('admin_logged_in')) {
            return redirect()->route('admin.index');
        }

        $rememberToken = $request->cookie(self::REMEMBER_COOKIE_NAME);
        if (self::isValidRememberToken($rememberToken)) {
            $request->session()->put('admin_logged_in', true);

            return redirect()->route('admin.index');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $configUser = (string) config('wcinfo.admin.user');
        $configPass = (string) config('wcinfo.admin.password');

        if (
            ! empty($configUser) &&
            hash_equals($configUser, $validated['username']) &&
            hash_equals($configPass, $validated['password'])
        ) {
            $request->session()->regenerate();
            $request->session()->put('admin_logged_in', true);

            $response = redirect()->intended(route('admin.index'))
                ->with('success', 'Successfully logged in as administrator.');

            if ($request->boolean('remember')) {
                $token = self::generateRememberToken();
                $response->withCookie(
                    Cookie::make(
                        self::REMEMBER_COOKIE_NAME,
                        $token,
                        self::REMEMBER_DURATION_MINUTES,
                        null,
                        null,
                        $request->isSecure(),
                        true // HttpOnly
                    )
                );
            }

            return $response;
        }

        return back()
            ->withInput($request->only('username'))
            ->withErrors(['username' => 'Invalid admin username or password.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_logged_in');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->withCookie(Cookie::forget(self::REMEMBER_COOKIE_NAME))
            ->with('info', 'You have been logged out.');
    }

    public static function generateRememberToken(): string
    {
        $configUser = (string) config('wcinfo.admin.user');
        $configPass = (string) config('wcinfo.admin.password');
        $appKey = (string) config('app.key');

        return hash_hmac('sha256', $configUser . ':' . $configPass, $appKey);
    }

    public static function isValidRememberToken(?string $token): bool
    {
        if (empty($token) || ! is_string($token)) {
            return false;
        }

        $expected = self::generateRememberToken();

        return hash_equals($expected, $token);
    }
}
