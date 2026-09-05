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

    public function test_logout_clears_session(): void
    {
        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/logout');

        $response->assertRedirect(route('admin.login'));
        $this->assertNull(session('admin_logged_in'));
    }
}
