<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Services\RateLimitService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminApiKeyManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_unauthenticated_user_cannot_access_api_keys_panel(): void
    {
        $response = $this->get('/admin/api-keys');
        $response->assertRedirect('/admin/login');
    }

    public function test_authenticated_admin_can_view_api_keys_dashboard(): void
    {
        $uniqueKey = 'wc_ios_unique_' . uniqid();
        $key = ApiKey::create([
            'key' => $uniqueKey,
            'name' => 'iOS App Unique',
            'description' => 'Official iOS client',
            'is_active' => true,
            'rate_limit_per_minute' => 40,
            'rate_limit_penalty_period' => 300,
            'rate_limit_block_duration' => 120,
            'global_rate_limit_per_minute' => 800,
        ]);

        $response = $this->withSession(['admin_logged_in' => true])->get('/admin/api-keys');

        $response->assertStatus(200)
            ->assertSee('API Keys')
            ->assertSee('iOS App Unique')
            ->assertSee($uniqueKey)
            ->assertSee('800 req/min');
    }

    public function test_admin_can_create_new_api_key(): void
    {
        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/api-keys', [
                'name' => 'Partner API',
                'description' => 'Third-party integration',
                'rate_limit_per_minute' => 50,
                'global_rate_limit_per_minute' => 1000,
                'rate_limit_block_duration' => 60,
                'rate_limit_penalty_period' => 180,
            ]);

        $response->assertRedirect(route('admin.api_keys.index'));
        $this->assertDatabaseHas('api_keys', [
            'name' => 'Partner API',
            'rate_limit_per_minute' => 50,
            'global_rate_limit_per_minute' => 1000,
        ]);
    }

    public function test_admin_can_update_existing_api_key(): void
    {
        $key = ApiKey::create([
            'key' => 'wc_test_update_' . uniqid(),
            'name' => 'Old Name',
            'is_active' => true,
            'rate_limit_per_minute' => 40,
            'rate_limit_penalty_period' => 300,
            'rate_limit_block_duration' => 120,
            'global_rate_limit_per_minute' => 800,
        ]);

        $response = $this->withSession(['admin_logged_in' => true])
            ->post("/admin/api-keys/{$key->id}", [
                'name' => 'Updated Name',
                'description' => 'Updated Description',
                'rate_limit_per_minute' => 60,
                'global_rate_limit_per_minute' => 1200,
                'rate_limit_block_duration' => 180,
                'rate_limit_penalty_period' => 400,
            ]);

        $response->assertRedirect(route('admin.api_keys.index'));
        $this->assertDatabaseHas('api_keys', [
            'id' => $key->id,
            'name' => 'Updated Name',
            'rate_limit_per_minute' => 60,
            'global_rate_limit_per_minute' => 1200,
        ]);
    }

    public function test_admin_can_toggle_api_key_active_status(): void
    {
        $key = ApiKey::create([
            'key' => 'wc_toggle_key_' . uniqid(),
            'name' => 'Toggle Key',
            'is_active' => true,
            'rate_limit_per_minute' => 40,
            'rate_limit_penalty_period' => 300,
            'rate_limit_block_duration' => 120,
            'global_rate_limit_per_minute' => 800,
        ]);

        $response = $this->withSession(['admin_logged_in' => true])
            ->post("/admin/api-keys/{$key->id}/toggle");

        $response->assertRedirect(route('admin.api_keys.index'));
        $key->refresh();
        $this->assertFalse($key->is_active);

        // Toggle back
        $this->withSession(['admin_logged_in' => true])
            ->post("/admin/api-keys/{$key->id}/toggle");
        $key->refresh();
        $this->assertTrue($key->is_active);
    }

    public function test_admin_can_regenerate_api_key_token(): void
    {
        $oldToken = 'wc_old_token_' . uniqid();
        $key = ApiKey::create([
            'key' => $oldToken,
            'name' => 'iOS App',
            'is_active' => true,
            'rate_limit_per_minute' => 40,
            'rate_limit_penalty_period' => 300,
            'rate_limit_block_duration' => 120,
            'global_rate_limit_per_minute' => 800,
        ]);

        $response = $this->withSession(['admin_logged_in' => true])
            ->post("/admin/api-keys/{$key->id}/regenerate");

        $response->assertRedirect(route('admin.api_keys.index'));
        $key->refresh();
        $this->assertNotEquals($oldToken, $key->key);
        $this->assertStringStartsWith('wc_ios_', $key->key);
    }

    public function test_admin_can_unblock_ip(): void
    {
        $key = ApiKey::create([
            'key' => 'wc_unblock_key_' . uniqid(),
            'name' => 'Test Key',
            'is_active' => true,
            'rate_limit_per_minute' => 40,
            'rate_limit_penalty_period' => 300,
            'rate_limit_block_duration' => 120,
            'global_rate_limit_per_minute' => 800,
        ]);

        $rateLimitService = app(RateLimitService::class);
        // Simulate blocked IP
        Cache::put("rl:blocked:{$key->id}:192.168.1.50", time() + 120, 120);
        Cache::put("rl:penalty:{$key->id}:192.168.1.50", time() + 420, 420);

        $response = $this->withSession(['admin_logged_in' => true])
            ->post('/admin/api-keys/unblock', [
                'api_key_id' => $key->id,
                'ip' => '192.168.1.50',
            ]);

        $response->assertRedirect(route('admin.api_keys.index'));
        $this->assertFalse(Cache::has("rl:blocked:{$key->id}:192.168.1.50"));
        $this->assertFalse(Cache::has("rl:penalty:{$key->id}:192.168.1.50"));
    }
}
