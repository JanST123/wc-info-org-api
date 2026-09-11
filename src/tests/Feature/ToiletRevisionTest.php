<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\ToiletProperty;
use App\Models\ToiletRevision;
use App\Services\ToiletRevisionService;
use Tests\TestCase;

class ToiletRevisionTest extends TestCase
{
    private array $createdToiletIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletRevision::whereIn('toilet_id', $this->createdToiletIds)->delete();
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        parent::tearDown();
    }

    public function test_api_add_toilet_creates_initial_revision(): void
    {
        $payload = [
            'name' => 'Initial Test Toilet',
            'owner' => 'Owner A',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'status' => 'active',
            'is_unisex' => true,
            'address' => 'Test Street 1',
        ];

        $response = $this->postJson('/toilet/add', $payload);
        $response->assertStatus(200);

        $toiletId = $response->json('id');
        $this->assertNotNull($toiletId);
        $this->createdToiletIds[] = $toiletId;

        $toilet = Toilet::find($toiletId);
        $this->assertEquals(1, $toilet->version);

        $revisions = ToiletRevision::where('toilet_id', $toiletId)->get();
        $this->assertCount(1, $revisions);
        $this->assertEquals(1, $revisions[0]->version);
        $this->assertEquals('api_add', $revisions[0]->source);
        $this->assertEquals('Initial Test Toilet', $revisions[0]->toilet_data['name']);
        $this->assertEquals('Test Street 1', $revisions[0]->properties_data['address']);
        $this->assertEquals('1', $revisions[0]->properties_data['is_unisex']);
    }

    public function test_api_patch_toilet_increments_version_and_records_diff(): void
    {
        $toilet = Toilet::create([
            'name' => 'Original Name',
            'status' => 'active',
            'version' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        /** @var ToiletRevisionService $service */
        $service = $this->app->make(ToiletRevisionService::class);
        $service->recordRevision($toilet, 'initial');

        $patchResponse = $this->patchJson("/toilet/{$toilet->id}/update", [
            'name' => 'Updated Name',
            'comment' => 'New comment added',
        ]);
        $patchResponse->assertStatus(200);

        $toilet->refresh();
        $this->assertEquals(2, $toilet->version);
        $this->assertEquals('Updated Name', $toilet->name);

        $revisions = ToiletRevision::where('toilet_id', $toilet->id)->orderBy('version')->get();
        $this->assertCount(2, $revisions);

        $rev2 = $revisions[1];
        $this->assertEquals(2, $rev2->version);
        $this->assertEquals('api_patch', $rev2->source);
        $this->assertArrayHasKey('name', $rev2->diff);
        $this->assertEquals('Original Name', $rev2->diff['name']['old']);
        $this->assertEquals('Updated Name', $rev2->diff['name']['new']);
        $this->assertEquals('New comment added', $rev2->properties_data['comment']);
    }

    public function test_admin_update_and_restore_version(): void
    {
        // 1. Create toilet v1 with comment "V1 Comment"
        $toilet = Toilet::create([
            'name' => 'Toilet for Restore Test',
            'status' => 'active',
            'lat' => 52.5100,
            'lon' => 13.4100,
            'version' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        ToiletProperty::create([
            'fk_toiletId' => $toilet->id,
            'type' => 'comment',
            'value' => 'V1 Comment',
        ]);

        /** @var ToiletRevisionService $service */
        $service = $this->app->make(ToiletRevisionService::class);
        $rev1 = $service->recordRevision($toilet, 'initial');
        $this->assertEquals(1, $rev1->version);

        // 2. Admin updates toilet to v2 with comment "V2 Edited Comment" and lat 52.5500
        $updatePayload = [
            'name' => 'Toilet v2 Name',
            'status' => 'active',
            'lat' => 52.5500,
            'lon' => 13.4100,
            'comment' => 'V2 Edited Comment',
        ];

        $adminResponse = $this->withSession(['admin_logged_in' => true])
            ->post("/admin/toilets/{$toilet->id}", $updatePayload);

        $adminResponse->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));

        $toilet->refresh();
        $this->assertEquals(2, $toilet->version);
        $this->assertEquals('Toilet v2 Name', $toilet->name);
        $this->assertEquals('V2 Edited Comment', $toilet->propertyValue('comment'));

        // 3. View show view contains revisions
        $showResponse = $this->withSession(['admin_logged_in' => true])
            ->get("/admin/toilets/{$toilet->id}");
        $showResponse->assertStatus(200);
        $showResponse->assertSee('v1');
        $showResponse->assertSee('v2');
        $showResponse->assertSee('Version History');

        // 4. Admin restores version 1
        $restoreResponse = $this->withSession(['admin_logged_in' => true])
            ->post("/admin/toilets/{$toilet->id}/restore-version/1");

        $restoreResponse->assertRedirect(route('admin.toilets.show', ['id' => $toilet->id]));

        $toilet->refresh();
        // Should have created version 3 restoring version 1's data
        $this->assertEquals(3, $toilet->version);
        $this->assertEquals('Toilet for Restore Test', $toilet->name);
        $this->assertEquals(52.5100, $toilet->lat);
        $this->assertEquals('V1 Comment', $toilet->propertyValue('comment'));

        $revisions = ToiletRevision::where('toilet_id', $toilet->id)->orderBy('version')->get();
        $this->assertCount(3, $revisions);
        $this->assertEquals('restore', $revisions[2]->source);
        $this->assertEquals('Restored from version 1', $revisions[2]->summary);
    }

    public function test_malformed_json_returns_400_bad_request(): void
    {
        $toilet = Toilet::create([
            'name' => 'Original Toilet',
            'status' => 'active',
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->call(
            'PATCH',
            "/toilet/{$toilet->id}/update",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{ "name": "gefdddfg", "comment": "s", }'
        );

        $response->assertStatus(400);
        $this->assertStringContainsString('Malformed JSON', $response->json('error'));
    }
}

