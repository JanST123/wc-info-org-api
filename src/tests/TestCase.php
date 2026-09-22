<?php

namespace Tests;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a test API key exists for test requests
        try {
            $testKey = 'wc_test_key_12345';
            ApiKey::firstOrCreate(
                ['key' => $testKey],
                [
                    'name' => 'Test Client',
                    'is_active' => true,
                    'rate_limit_per_minute' => 5000,
                    'rate_limit_penalty_period' => 300,
                    'rate_limit_block_duration' => 120,
                    'global_rate_limit_per_minute' => 10000,
                ]
            );

            $this->withHeader('X-Api-Key', $testKey);
        } catch (\Throwable $e) {
            // In case database is not available in pure unit tests
        }
    }
}
