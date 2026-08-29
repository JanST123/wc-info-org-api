<?php

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Services\MailService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class NotifyUpdatedToiletsCommandTest extends TestCase
{
    public function test_notifies_when_photo_is_deleted(): void
    {
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())
            ->method('send')
            ->with(
                'hallo@wc-info.de',
                $this->stringContains('deleted photo(s)'),
                $this->logicalAnd(
                    $this->stringContains('Deleted Photos'),
                    $this->stringContains('deleted_photo_test.jpg')
                ),
                true
            );

        $this->app->instance(MailService::class, $mail);

        $toilet = Toilet::create([
            'name' => 'WC Notification Test',
            'owner' => 'Owner Test',
            'lat' => 52.0,
            'lon' => 13.0,
            'status' => 'active',
            'email_sent' => 1,
        ]);

        $photo = ToiletPhoto::create([
            'fk_toiletId' => $toilet->id,
            'filename' => '_DELETED_deleted_photo_test.jpg',
            'deleted_ts' => now(),
            'email_sent' => 2,
        ]);

        $status = Artisan::call('app:notify-updated-toilets');

        $this->assertSame(0, $status);

        $photo->refresh();
        $this->assertSame(1, $photo->email_sent);

        $photo->delete();
        $toilet->delete();
    }
}
