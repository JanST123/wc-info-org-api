<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Services\S3PhotoStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhotoUploadTest extends TestCase
{
    private string $apiKey = 'test-upload-api-key';

    private array $createdToiletIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        ApiKey::updateOrCreate(
            ['key' => $this->apiKey],
            [
                'name' => 'Test Upload Key',
                'is_active' => true,
                'rate_limit_per_minute' => 100,
                'block_duration_seconds' => 120,
                'slowdown_duration_seconds' => 300,
                'slowdown_rate_limit' => 20,
                'global_limit_per_minute' => 1000,
            ]
        );
    }

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletPhoto::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        ApiKey::where('key', $this->apiKey)->delete();

        parent::tearDown();
    }

    public function test_uploading_jpeg_photo_succeeds(): void
    {
        $mockS3 = $this->createMock(S3PhotoStorageService::class);
        $mockS3->expects($this->exactly(2))
            ->method('put');
        $this->app->instance(S3PhotoStorageService::class, $mockS3);

        $file = UploadedFile::fake()->image('toilet.jpg', 640, 480);

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->post('/upload', [
                'file' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $toiletId = $response->json('toiletId');
        $filename = $response->json('filename');
        $this->createdToiletIds[] = $toiletId;

        $this->assertStringEndsWith('.jpg', $filename);
        $this->assertDatabaseHas('toilet_photos', [
            'fk_toiletId' => $toiletId,
            'filename' => $filename,
        ]);
    }

    public function test_uploading_png_photo_succeeds(): void
    {
        $mockS3 = $this->createMock(S3PhotoStorageService::class);
        $mockS3->expects($this->exactly(2))
            ->method('put');
        $this->app->instance(S3PhotoStorageService::class, $mockS3);

        $file = UploadedFile::fake()->image('toilet.png', 640, 480);

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->post('/upload', [
                'file' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $toiletId = $response->json('toiletId');
        $filename = $response->json('filename');
        $this->createdToiletIds[] = $toiletId;

        $this->assertStringEndsWith('.png', $filename);
        $this->assertDatabaseHas('toilet_photos', [
            'fk_toiletId' => $toiletId,
            'filename' => $filename,
        ]);
    }

    public function test_uploading_webp_photo_succeeds(): void
    {
        $mockS3 = $this->createMock(S3PhotoStorageService::class);
        $mockS3->expects($this->exactly(2))
            ->method('put');
        $this->app->instance(S3PhotoStorageService::class, $mockS3);

        // Generate a valid webp file content using GD
        $img = imagecreatetruecolor(100, 100);
        $tempWebp = tempnam(sys_get_temp_dir(), 'test_webp').'.webp';
        imagewebp($img, $tempWebp);
        imagedestroy($img);

        $file = new UploadedFile($tempWebp, 'toilet.webp', 'image/webp', null, true);

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->post('/upload', [
                'file' => $file,
            ]);

        @unlink($tempWebp);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $toiletId = $response->json('toiletId');
        $filename = $response->json('filename');
        $this->createdToiletIds[] = $toiletId;

        $this->assertStringEndsWith('.webp', $filename);
        $this->assertDatabaseHas('toilet_photos', [
            'fk_toiletId' => $toiletId,
            'filename' => $filename,
        ]);
    }

    public function test_uploading_unsupported_format_fails_validation(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->post('/upload', [
                'file' => $file,
            ]);

        $response->assertStatus(400);
        $response->assertJsonValidationErrors(['file']);
    }
}
