<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect(route('admin.login'));

        $response2 = $this->get('/admin/toilets/1');
        $response2->assertRedirect(route('admin.login'));
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get('/admin/login');
        $response->assertStatus(200);
        $response->assertSee('Admin Panel Authentication');
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        $response = $this->post('/admin/login', [
            'username' => 'wrong-user',
            'password' => 'wrong-pass',
        ]);

        $response->assertSessionHasErrors(['username']);
        $this->assertNull(session('admin_logged_in'));
    }

    public function test_login_with_valid_credentials_succeeds(): void
    {
        config(['wcinfo.admin.user' => 'testadmin']);
        config(['wcinfo.admin.password' => 'testpassword123']);

        $response = $this->post('/admin/login', [
            'username' => 'testadmin',
            'password' => 'testpassword123',
        ]);

        $response->assertRedirect(route('admin.index'));
        $this->assertTrue(session('admin_logged_in'));

        // Accessing admin index after login
        $dashResponse = $this->withSession(['admin_logged_in' => true])->get('/admin');
        $dashResponse->assertStatus(200);
        $dashResponse->assertSee('Admin Dashboard');
    }

    public function test_login_with_remember_me_attaches_cookie(): void
    {
        config(['wcinfo.admin.user' => 'testadmin']);
        config(['wcinfo.admin.password' => 'testpassword123']);

        $response = $this->post('/admin/login', [
            'username' => 'testadmin',
            'password' => 'testpassword123',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('admin.index'));
        $response->assertCookie('admin_remember');
    }

    public function test_user_with_valid_remember_cookie_is_authenticated_without_session(): void
    {
        config(['wcinfo.admin.user' => 'testadmin']);
        config(['wcinfo.admin.password' => 'testpassword123']);

        $token = \App\Http\Controllers\Admin\AdminAuthController::generateRememberToken();

        // Access protected route with remember cookie and no session
        $response = $this->withCookie('admin_remember', $token)
            ->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Admin Dashboard');

        // Access login page with remember cookie should redirect to dashboard
        $loginResponse = $this->withCookie('admin_remember', $token)
            ->get('/admin/login');

        $loginResponse->assertRedirect(route('admin.index'));
    }

    public function test_user_with_invalid_remember_cookie_is_redirected(): void
    {
        config(['wcinfo.admin.user' => 'testadmin']);
        config(['wcinfo.admin.password' => 'testpassword123']);

        $response = $this->withCookie('admin_remember', 'invalid-token-12345')
            ->get('/admin');

        $response->assertRedirect(route('admin.login'));
    }

    public function test_logout_clears_session_and_cookie(): void
    {
        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/logout');

        $response->assertRedirect(route('admin.login'));
        $response->assertCookieExpired('admin_remember');
        $this->assertNull(session('admin_logged_in'));
    }
}
