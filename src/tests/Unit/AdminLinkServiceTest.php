<?php

namespace Tests\Unit;

use App\Services\AdminLinkService;
use PHPUnit\Framework\TestCase;

class AdminLinkServiceTest extends TestCase
{
    public function test_hash_is_consistent(): void
    {
        $service = new AdminLinkService('test-secret-1', 'test-secret-2');

        $this->assertEquals(
            $service->hash(123, 'placeABC'),
            $service->hash(123, 'placeABC')
        );
    }

    public function test_hash_changes_with_id_or_place(): void
    {
        $service = new AdminLinkService('test-secret-1', 'test-secret-2');

        $this->assertNotEquals(
            $service->hash(123, 'placeABC'),
            $service->hash(124, 'placeABC')
        );

        $this->assertNotEquals(
            $service->hash(123, 'placeABC'),
            $service->hash(123, 'placeABD')
        );
    }

    public function test_verify_valid_hash(): void
    {
        $service = new AdminLinkService('test-secret-1', 'test-secret-2');
        $hash = $service->hash(123, 'placeABC');

        $this->assertTrue($service->verify(123, 'placeABC', $hash));
    }

    public function test_verify_invalid_hash(): void
    {
        $service = new AdminLinkService('test-secret-1', 'test-secret-2');

        $this->assertFalse($service->verify(123, 'placeABC', 'wronghash'));
    }
}
