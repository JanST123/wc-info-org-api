<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('admin_logged_in')) {
            return redirect()->route('admin.index');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
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

            return redirect()->intended(route('admin.index'))
                ->with('success', 'Successfully logged in as administrator.');
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
            ->with('info', 'You have been logged out.');
    }
}
