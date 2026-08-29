<?php

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Services\S3PhotoStorageService;
use Tests\TestCase;

class DeletePhotoTest extends TestCase
{
    public function test_soft_delete_photo_by_default(): void
    {
        $s3 = $this->createMock(S3PhotoStorageService::class);
        $s3->method('exists')->willReturn(true);
        $s3->expects($this->exactly(2))
            ->method('rename')
            ->willReturnCallback(function ($toiletId, $oldName, $newName) {
                $this->assertStringStartsWith('_DELETED_', $newName);
                return true;
            });

        $this->app->instance(S3PhotoStorageService::class, $s3);

        $toilet = Toilet::create([
            'name' => 'WC Photo Test',
            'owner' => 'Test',
            'lat' => 52.0,
            'lon' => 13.0,
            'status' => 'active',
        ]);

        $photo = ToiletPhoto::create([
            'fk_toiletId' => $toilet->id,
            'filename' => 'test_photo_123.jpg',
            'filename_thumb' => 'test_photo_123.thumb.jpg',
            'email_sent' => 0,
        ]);

        $response = $this->deleteJson("/deletePhoto/{$toilet->id}/test_photo_123.jpg");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'soft' => true,
            'filename' => '_DELETED_test_photo_123.jpg',
        ]);

        $photo->refresh();
        $this->assertSame('_DELETED_test_photo_123.jpg', $photo->filename);
        $this->assertSame('_DELETED_test_photo_123.thumb.jpg', $photo->filename_thumb);
        $this->assertNotNull($photo->deleted_ts);
        $this->assertSame(2, $photo->email_sent);

        // Active photos relation on toilet should not include soft-deleted photo
        $toilet->refresh();
        $this->assertCount(0, $toilet->photos);

        $photo->delete();
        $toilet->delete();
    }

    public function test_hard_delete_photo_when_soft_is_false(): void
    {
        $s3 = $this->createMock(S3PhotoStorageService::class);
        $s3->method('exists')->willReturn(true);
        $s3->expects($this->exactly(2))
            ->method('delete')
            ->willReturn(true);

        $this->app->instance(S3PhotoStorageService::class, $s3);

        $toilet = Toilet::create([
            'name' => 'WC Photo Test Hard',
            'owner' => 'Test',
            'lat' => 52.0,
            'lon' => 13.0,
            'status' => 'active',
        ]);

        $photo = ToiletPhoto::create([
            'fk_toiletId' => $toilet->id,
            'filename' => 'hard_delete_123.jpg',
            'filename_thumb' => 'hard_delete_123.thumb.jpg',
        ]);

        $response = $this->deleteJson("/deletePhoto/{$toilet->id}/hard_delete_123.jpg?soft=0");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'soft' => false,
            'deletedCount' => 2,
        ]);

        $this->assertNull(ToiletPhoto::find($photo->id));

        $toilet->delete();
    }
}
