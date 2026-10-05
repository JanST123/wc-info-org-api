<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletProperty;
use App\Models\ToiletRevision;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotifyFlaggedToiletsCommandTest extends TestCase
{
    private array $createdToiletIds = [];

    private array $createdPlaceIds = [];

    private array $originallyFlaggedIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originallyFlaggedIds = Toilet::where('flagged', 1)->pluck('id')->toArray();
        if (! empty($this->originallyFlaggedIds)) {
            Toilet::whereIn('id', $this->originallyFlaggedIds)->update(['flagged' => 0]);
        }
    }

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
            DB::table('type_x_place')->whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        if (! empty($this->originallyFlaggedIds)) {
            Toilet::whereIn('id', $this->originallyFlaggedIds)->update(['flagged' => 1]);
        }

        parent::tearDown();
    }

    public function test_does_not_send_mail_when_no_flagged_toilets_exist(): void
    {
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        $this->app->instance(MailService::class, $mail);

        $toilet = Toilet::create([
            'name' => 'Unflagged Toilet',
            'status' => 'active',
            'flagged' => false,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $exitCode = Artisan::call('app:notify-flagged-toilets');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No flagged toilets found', Artisan::output());
    }

    public function test_does_not_send_mail_when_flagged_toilet_is_newer_than_48_hours(): void
    {
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        $this->app->instance(MailService::class, $mail);

        $toilet = Toilet::create([
            'name' => 'Recently Flagged Toilet',
            'status' => 'active',
            'flagged' => true,
            'created_at' => Carbon::now()->subHours(12),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        ToiletRevision::create([
            'toilet_id' => $toilet->id,
            'version' => 1,
            'source' => 'initial',
            'toilet_data' => ['name' => $toilet->name, 'flagged' => true],
            'properties_data' => [],
            'created_at' => Carbon::now()->subHours(12),
        ]);

        $exitCode = Artisan::call('app:notify-flagged-toilets');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No toilets flagged for more than 48 hours', Artisan::output());
    }

    public function test_sends_daily_mail_when_toilet_is_flagged_for_more_than_48_hours(): void
    {
        $placeId = 'place_flagged_mail_'.uniqid();
        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Public Restroom Station'],
                'types' => ['public_bathroom'],
            ],
        ]);
        $this->createdPlaceIds[] = $placeId;

        $toilet = Toilet::create([
            'name' => 'Old Flagged Toilet',
            'owner' => 'Station Management',
            'status' => 'active',
            'flagged' => true,
            'place_id' => $placeId,
            'source' => 'auto_crawl_with_public_bathroom',
            'created_at' => Carbon::now()->subHours(72),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        ToiletRevision::create([
            'toilet_id' => $toilet->id,
            'version' => 1,
            'source' => 'auto_crawl',
            'toilet_data' => ['name' => $toilet->name, 'flagged' => true],
            'properties_data' => [],
            'created_at' => Carbon::now()->subHours(72),
        ]);

        ToiletProperty::create([
            'fk_toiletId' => $toilet->id,
            'type' => 'address',
            'value' => 'Bahnhofsplatz 1, Berlin',
        ]);

        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())
            ->method('send')
            ->with(
                config('wcinfo.sender_mail'),
                $this->stringContains('1 toilet(s) flagged for >48 hours!'),
                $this->logicalAnd(
                    $this->stringContains('Old Flagged Toilet'),
                    $this->stringContains('Toilet #'.$toilet->id),
                    $this->stringContains('Station Management'),
                    $this->stringContains('Bahnhofsplatz 1, Berlin'),
                    $this->stringContains('https://api.wc-info.org/admin/toilets/'.$toilet->id)
                ),
                true
            );
        $this->app->instance(MailService::class, $mail);

        $exitCode = Artisan::call('app:notify-flagged-toilets');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Notification email sent for 1 toilet(s)', Artisan::output());
    }

    public function test_tracks_flagged_streak_through_multiple_revisions(): void
    {
        $toilet = Toilet::create([
            'name' => 'Multi-revision Flagged Toilet',
            'status' => 'active',
            'flagged' => true,
            'created_at' => Carbon::now()->subDays(5),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        // Revision 1 (5 days ago): Flagged
        ToiletRevision::create([
            'toilet_id' => $toilet->id,
            'version' => 1,
            'source' => 'initial',
            'toilet_data' => ['name' => 'Initial Name', 'flagged' => true],
            'properties_data' => [],
            'created_at' => Carbon::now()->subDays(5),
        ]);

        // Revision 2 (10 hours ago): Updated name, but stayed flagged
        ToiletRevision::create([
            'toilet_id' => $toilet->id,
            'version' => 2,
            'source' => 'edit',
            'toilet_data' => ['name' => 'Multi-revision Flagged Toilet', 'flagged' => true],
            'properties_data' => [],
            'created_at' => Carbon::now()->subHours(10),
        ]);

        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())
            ->method('send')
            ->with(
                config('wcinfo.sender_mail'),
                $this->stringContains('1 toilet(s) flagged for >48 hours!'),
                $this->stringContains('Multi-revision Flagged Toilet'),
                true
            );
        $this->app->instance(MailService::class, $mail);

        $exitCode = Artisan::call('app:notify-flagged-toilets');

        $this->assertSame(0, $exitCode);
    }

    public function test_dry_run_option_does_not_send_mail(): void
    {
        $toilet = Toilet::create([
            'name' => 'Dry Run Flagged Toilet',
            'status' => 'active',
            'flagged' => true,
            'created_at' => Carbon::now()->subHours(60),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        $this->app->instance(MailService::class, $mail);

        $exitCode = Artisan::call('app:notify-flagged-toilets', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[DRY-RUN]', Artisan::output());
    }
}
