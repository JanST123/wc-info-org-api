<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Services\MailService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiKeyRateLimitTest extends TestCase
{
    private ApiKey $activeKey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->activeKey = ApiKey::firstOrCreate(
            ['key' => 'wc_test_key_abc123'],
            [
                'name' => 'Test Key',
                'description' => 'For unit testing',
                'is_active' => true,
                'rate_limit_per_minute' => 5,
                'rate_limit_penalty_period' => 10,
                'rate_limit_block_duration' => 5,
                'global_rate_limit_per_minute' => 15,
            ]
        );
        $this->activeKey->update(['is_active' => true]);
    }

    public function test_missing_api_key_returns_401(): void
    {
        // Clear default headers
        $response = $this->withHeaders(['X-Api-Key' => ''])->getJson('/toilets/nearby/52.52/13.405');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
            ]);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => 'wc_invalid_key_99999'])->getJson('/toilets/nearby/52.52/13.405');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
            ]);
    }

    public function test_inactive_api_key_returns_401(): void
    {
        $this->activeKey->update(['is_active' => false]);

        $response = $this->withHeaders(['X-Api-Key' => $this->activeKey->key])->getJson('/toilets/nearby/52.52/13.405');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
            ]);
    }

    public function test_valid_api_key_header_succeeds_and_attaches_headers(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => $this->activeKey->key])->getJson('/toilets/nearby/52.52/13.405');

        $response->assertStatus(200);
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertEquals('5', $response->headers->get('X-RateLimit-Limit'));
    }

    public function test_bearer_token_authorization_succeeds(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->activeKey->key,
            'X-Api-Key' => '',
        ])->getJson('/toilets/nearby/52.52/13.405');

        $response->assertStatus(200);
        $this->assertEquals('5', $response->headers->get('X-RateLimit-Limit'));
    }

    public function test_query_parameter_api_key_succeeds(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => ''])
            ->getJson('/toilets/nearby/52.52/13.405?api_key=' . $this->activeKey->key);

        $response->assertStatus(200);
    }

    public function test_exempt_routes_do_not_require_api_key(): void
    {
        // /health
        $health = $this->withHeaders(['X-Api-Key' => ''])->getJson('/health');
        $health->assertStatus(200);

        // /sitemap
        $sitemap = $this->withHeaders(['X-Api-Key' => ''])->get('/sitemap');
        $sitemap->assertStatus(200);

        // /admin/login
        $admin = $this->withHeaders(['X-Api-Key' => ''])->get('/admin/login');
        $admin->assertStatus(200);
    }

    public function test_hard_block_triggers_when_per_ip_limit_exceeded(): void
    {
        $headers = ['X-Api-Key' => $this->activeKey->key];

        // Perform 5 allowed requests (limit is 5)
        for ($i = 0; $i < 5; $i++) {
            $res = $this->withHeaders($headers)->getJson('/toilets/nearby/52.52/13.405');
            $res->assertStatus(200);
        }

        // 6th request triggers hard-block
        $blocked = $this->withHeaders($headers)->getJson('/toilets/nearby/52.52/13.405');
        $blocked->assertStatus(429)
            ->assertJson([
                'error' => 'Too Many Requests',
            ]);

        $this->assertTrue($blocked->headers->has('Retry-After'));
        $this->assertEquals(5, (int) $blocked->headers->get('Retry-After'));

        // Subsequent requests are blocked immediately
        $subsequent = $this->withHeaders($headers)->getJson('/toilets/nearby/52.52/13.405');
        $subsequent->assertStatus(429);
    }

    public function test_global_limit_ceiling_triggers_and_sends_alert(): void
    {
        $mailMock = $this->createMock(MailService::class);
        $mailMock->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->stringContains('ALERT: Global rate limit hit'),
                $this->anything(),
                false
            );
        $this->app->instance(MailService::class, $mailMock);

        // Populate global count to limit (15)
        $minuteBucket = (int) floor(time() / 60);
        Cache::put("rl:global:{$this->activeKey->id}:{$minuteBucket}", 15, 120);

        $response = $this->withHeaders(['X-Api-Key' => $this->activeKey->key])->getJson('/toilets/nearby/52.52/13.405');

        $response->assertStatus(429)
            ->assertJson([
                'error' => 'Too Many Requests',
                'message' => 'Global API rate limit exceeded. Please try again later.',
            ]);
    }

    public function test_openapi_documentation_includes_api_key_security_scheme(): void
    {
        $response = $this->get('/docs/api.json');

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertArrayHasKey('components', $json);
        $this->assertArrayHasKey('securitySchemes', $json['components']);
        $this->assertArrayHasKey('ApiKeyAuth', $json['components']['securitySchemes']);
        $this->assertEquals('apiKey', $json['components']['securitySchemes']['ApiKeyAuth']['type']);
        $this->assertEquals('header', $json['components']['securitySchemes']['ApiKeyAuth']['in']);
        $this->assertEquals('X-Api-Key', $json['components']['securitySchemes']['ApiKeyAuth']['name']);

        // Check that /toilets/nearby/{lat}/{lon} requires ApiKeyAuth
        $nearbyOp = $json['paths']['/toilets/nearby/{lat}/{lon}']['get'] ?? null;
        $this->assertNotNull($nearbyOp);
        $this->assertEquals([['ApiKeyAuth' => []]], $nearbyOp['security'] ?? $json['security'] ?? []);

        // Check description mentions authentication
        $this->assertStringContainsString('Authentication', $json['info']['description']);
    }
}
