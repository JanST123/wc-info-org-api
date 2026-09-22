<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletRevision;
use App\Services\MailService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ToiletFeedbackTest extends TestCase
{
    private array $createdToiletIds = [];

    private array $createdPlaceIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        if (! empty($this->createdPlaceIds)) {
            Place::whereIn('place_id', $this->createdPlaceIds)->delete();
        }

        parent::tearDown();
    }

    public function test_submitting_feedback_sends_email_with_prefixed_subject_and_enriched_body(): void
    {
        $placeId = 'place_feedback_'.uniqid();
        $this->createdPlaceIds[] = $placeId;

        Place::create([
            'place_id' => $placeId,
            'data' => [
                'id' => $placeId,
                'displayName' => ['text' => 'Alexanderplatz Station', 'languageCode' => 'de'],
            ],
        ]);

        $toilet = Toilet::create([
            'name' => 'WC Alexanderplatz #1',
            'owner' => 'Berliner Verkehrsbetriebe',
            'place_id' => $placeId,
            'status' => 'active',
            'created_at' => now()->subDays(10),
            'last_discovered' => now()->subDays(2),
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $senderMail = (string) config('wcinfo.sender_mail');

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())
            ->method('send')
            ->with(
                $this->equalTo($senderMail),
                $this->equalTo('Feedback: Defective soap dispenser'),
                $this->callback(function (string $body) use ($toilet): bool {
                    $this->assertStringContainsString('The soap dispenser on the left wall is empty and leaking.', $body);
                    $this->assertStringContainsString('WC Alexanderplatz #1', $body);
                    $this->assertStringContainsString('Berliner Verkehrsbetriebe', $body);
                    $this->assertStringContainsString('Alexanderplatz Station', $body);
                    $this->assertStringContainsString((string) $toilet->id, $body);
                    $this->assertStringContainsString('/admin/toilets/'.$toilet->id, $body);

                    return true;
                }),
                $this->isTrue()
            );

        $this->app->instance(MailService::class, $mailService);

        $response = $this->postJson("/toilet/feedback/{$toilet->id}", [
            'subject' => 'Defective soap dispenser',
            'message' => 'The soap dispenser on the left wall is empty and leaking.',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'okay',
                'message' => 'Feedback sent successfully',
            ]);
    }

    public function test_feedback_returns_404_when_toilet_does_not_exist(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $this->app->instance(MailService::class, $mailService);

        $response = $this->postJson('/toilet/feedback/99999999', [
            'subject' => 'Non-existent toilet',
            'message' => 'This toilet does not exist.',
        ]);

        $response->assertNotFound()
            ->assertJson([
                'status' => 'not_found',
            ]);
    }

    public function test_feedback_validation_requires_subject_and_message(): void
    {
        $toilet = Toilet::create([
            'name' => 'WC Test Valid',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->postJson("/toilet/feedback/{$toilet->id}", []);

        $response->assertStatus(400);
        $this->assertArrayHasKey('subject', $response->json('errors'));
        $this->assertArrayHasKey('message', $response->json('errors'));
    }

    public function test_feedback_accepts_body_as_alias_for_message(): void
    {
        $toilet = Toilet::create([
            'name' => 'WC Alias Test',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->equalTo('Feedback: Alias payload test'),
                $this->stringContains('Message sent with body key instead of message'),
                $this->isTrue()
            );

        $this->app->instance(MailService::class, $mailService);

        $response = $this->postJson("/toilet/feedback/{$toilet->id}", [
            'subject' => 'Alias payload test',
            'body' => 'Message sent with body key instead of message',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'okay',
            ]);
    }
}
