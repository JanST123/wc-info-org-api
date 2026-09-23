<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    public function test_wildcard_allowed_origins_sets_reflecting_origin_and_credentials_header(): void
    {
        Config::set('wcinfo.cors.allowed_origins', '*');
        Config::set('cors.allowed_origins', ['*']);

        // 1. Preflight OPTIONS
        $response = $this->call('OPTIONS', '/health', [], [], [], [
            'HTTP_ORIGIN' => 'https://example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://example.com');
        $this->assertNotEquals('*', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
        $this->assertStringContainsString('X-Api-Key', $response->headers->get('Access-Control-Allow-Headers') ?? '');
        $this->assertStringContainsString('Origin', $response->headers->get('Vary') ?? '');

        // 2. Standard GET request with Origin
        $getResponse = $this->get('/health', [
            'Origin' => 'https://example.com',
        ]);

        $getResponse->assertStatus(200);
        $getResponse->assertHeader('Access-Control-Allow-Origin', 'https://example.com');
        $this->assertNotEquals('*', $getResponse->headers->get('Access-Control-Allow-Origin'));
        $getResponse->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('Origin', $getResponse->headers->get('Vary') ?? '');
    }

    public function test_specific_allowed_origins_allows_matching_origin(): void
    {
        Config::set('wcinfo.cors.allowed_origins', 'https://wc-info.de, https://*.wc-info.org, http://localhost:3000');
        Config::set('cors.allowed_origins', ['https://wc-info.de', 'https://*.wc-info.org', 'http://localhost:3000']);

        // Matching exact domain
        $response1 = $this->call('OPTIONS', '/health', [], [], [], [
            'HTTP_ORIGIN' => 'https://wc-info.de',
        ]);
        $response1->assertStatus(204);
        $response1->assertHeader('Access-Control-Allow-Origin', 'https://wc-info.de');
        $response1->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('Origin', $response1->headers->get('Vary') ?? '');

        // Matching wildcard domain
        $response2 = $this->call('OPTIONS', '/health', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.wc-info.org',
        ]);
        $response2->assertStatus(204);
        $response2->assertHeader('Access-Control-Allow-Origin', 'https://app.wc-info.org');
        $response2->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('Origin', $response2->headers->get('Vary') ?? '');

        // Matching localhost port
        $response3 = $this->get('/health', [
            'Origin' => 'http://localhost:3000',
        ]);
        $response3->assertStatus(200);
        $response3->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $response3->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('Origin', $response3->headers->get('Vary') ?? '');
    }

    public function test_disallowed_origin_does_not_receive_any_cors_headers(): void
    {
        Config::set('wcinfo.cors.allowed_origins', 'https://wc-info.de');
        Config::set('cors.allowed_origins', ['https://wc-info.de']);

        $response = $this->call('OPTIONS', '/health', [], [], [], [
            'HTTP_ORIGIN' => 'https://unauthorized-site.com',
        ]);

        $response->assertStatus(204);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));

        $getResponse = $this->get('/health', [
            'Origin' => 'https://unauthorized-site.com',
        ]);

        $getResponse->assertStatus(200);
        $this->assertFalse($getResponse->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($getResponse->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_request_without_origin_header_does_not_send_any_cors_headers(): void
    {
        Config::set('wcinfo.cors.allowed_origins', '*');
        Config::set('cors.allowed_origins', ['*']);

        $response = $this->get('/health');
        $response->assertStatus(200);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Methods'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Headers'));
    }
}
