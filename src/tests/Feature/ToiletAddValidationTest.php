<?php

namespace Tests\Feature;

use App\Models\Toilet;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ToiletAddValidationTest extends TestCase
{
    private array $createdToiletIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            DB::table('toilet_properties')->whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        parent::tearDown();
    }

    public function test_cannot_have_both_is_unisex_and_is_gender_separated_true(): void
    {
        $response = $this->postJson('/toilet/add', [
            'lat' => 52.5200,
            'lon' => 13.4050,
            'is_unisex' => true,
            'is_gender_separated' => true,
        ]);

        $response->assertStatus(400);
        $response->assertJsonStructure(['message', 'errors']);
        $this->assertStringContainsString('"is_unisex" and "is_gender_separated" cannot both be true', $response->json('message'));
        $this->assertArrayHasKey('is_unisex', $response->json('errors'));
    }

    public function test_cannot_have_both_is_unisex_and_is_gender_separated_truthy_integers(): void
    {
        $response = $this->postJson('/toilet/add', [
            'lat' => 52.5200,
            'lon' => 13.4050,
            'is_unisex' => 1,
            'is_gender_separated' => 1,
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('"is_unisex" and "is_gender_separated" cannot both be true', $response->json('message'));
    }

    public function test_euro_key_rejects_invalid_values(): void
    {
        $invalidValues = ['1', '0', 'maybe', 'invalid', 'true', 'false'];

        foreach ($invalidValues as $invalid) {
            $response = $this->postJson('/toilet/add', [
                'lat' => 52.5200,
                'lon' => 13.4050,
                'euro_key' => $invalid,
            ]);

            $response->assertStatus(400);
            $this->assertArrayHasKey('euro_key', $response->json('errors'));
        }
    }

    public function test_euro_key_accepts_valid_values(): void
    {
        $validValues = ['yes', 'no', 'unknown'];

        foreach ($validValues as $valid) {
            $response = $this->postJson('/toilet/add', [
                'lat' => 52.5200,
                'lon' => 13.4050,
                'euro_key' => $valid,
            ]);

            $response->assertStatus(200);
            $response->assertJson(['success' => true]);

            $toiletId = $response->json('id');
            $this->createdToiletIds[] = $toiletId;

            $property = DB::table('toilet_properties')
                ->where('fk_toiletId', $toiletId)
                ->where('type', 'euro_key')
                ->first();

            $this->assertNotNull($property);
            $this->assertEquals($valid, $property->value);
        }
    }

    public function test_valid_unisex_and_gender_separated_individually(): void
    {
        $response1 = $this->postJson('/toilet/add', [
            'lat' => 52.5200,
            'lon' => 13.4050,
            'is_unisex' => true,
            'is_gender_separated' => false,
        ]);
        $response1->assertStatus(200);
        $this->createdToiletIds[] = $response1->json('id');

        $response2 = $this->postJson('/toilet/add', [
            'lat' => 52.5200,
            'lon' => 13.4050,
            'is_unisex' => false,
            'is_gender_separated' => true,
        ]);
        $response2->assertStatus(200);
        $this->createdToiletIds[] = $response2->json('id');
    }

    public function test_storage_space_validation_and_persistence(): void
    {
        // Invalid value returns 400
        $invalidResponse = $this->postJson('/toilet/add', [
            'lat' => 52.5200,
            'lon' => 13.4050,
            'storage_space' => 'huge',
        ]);
        $invalidResponse->assertStatus(400);
        $invalidResponse->assertJsonStructure(['message', 'errors']);
        $this->assertArrayHasKey('storage_space', $invalidResponse->json('errors'));

        // Valid values ('none', 'little', 'much')
        foreach (['none', 'little', 'much'] as $val) {
            $response = $this->postJson('/toilet/add', [
                'lat' => 52.5200,
                'lon' => 13.4050,
                'storage_space' => $val,
            ]);
            $response->assertStatus(200);
            $toiletId = $response->json('id');
            $this->createdToiletIds[] = $toiletId;

            $prop = DB::table('toilet_properties')
                ->where('fk_toiletId', $toiletId)
                ->where('type', 'storage_space')
                ->first();
            $this->assertNotNull($prop);
            $this->assertEquals($val, $prop->value);
        }
    }

    public function test_accessible_flags_and_storage_space_crud_flow(): void
    {
        // 1. POST /toilet/add
        $createResponse = $this->postJson('/toilet/add', [
            'name' => 'New Feature Toilet',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'accessible_outside_opening_times' => true,
            'public_accessible' => true,
            'storage_space' => 'much',
            'comment' => 'Entrance on the left side',
            'website' => 'https://example.com/wc',
        ]);
        $createResponse->assertStatus(200);
        $toiletId = $createResponse->json('id');
        $this->createdToiletIds[] = $toiletId;

        // 2. GET /toilet/{id} (ToiletDetailResource)
        $detailResponse = $this->getJson("/toilet/{$toiletId}");
        $detailResponse->assertStatus(200);
        $detail = $detailResponse->json();
        $this->assertTrue($detail['flags']['accessible_outside_opening_times']);
        $this->assertTrue($detail['flags']['public_accessible']);
        $this->assertEquals('much', $detail['properties']['storage_space']);
        $this->assertEquals('Entrance on the left side', $detail['properties']['comment']);
        $this->assertEquals('https://example.com/wc', $detail['properties']['website']);

        // 3. GET /toilets/bounds/... (ToiletListResource)
        $listResponse = $this->getJson('/toilets/bounds/52.0/13.0/53.0/14.0');
        $listResponse->assertStatus(200);
        $item = collect($listResponse->json())->firstWhere('id', $toiletId);
        $this->assertNotNull($item);
        $this->assertTrue($item['accessible_outside_opening_times']);
        $this->assertTrue($item['public_accessible']);
        $this->assertEquals('much', $item['storage_space']);
        $this->assertEquals('Entrance on the left side', $item['comment']);
        $this->assertEquals('https://example.com/wc', $item['website']);

        // 4. PATCH /toilet/{id}/update
        $updateResponse = $this->patchJson("/toilet/{$toiletId}/update", [
            'accessible_outside_opening_times' => false,
            'public_accessible' => false,
            'storage_space' => 'little',
            'comment' => 'Updated comment',
            'website' => 'https://example.com/new-wc',
        ]);
        $updateResponse->assertStatus(200);

        // 5. GET /toilet/{id} after update
        $updatedDetailResponse = $this->getJson("/toilet/{$toiletId}");
        $updatedDetailResponse->assertStatus(200);
        $updatedDetail = $updatedDetailResponse->json();
        $this->assertFalse($updatedDetail['flags']['accessible_outside_opening_times']);
        $this->assertFalse($updatedDetail['flags']['public_accessible']);
        $this->assertEquals('little', $updatedDetail['properties']['storage_space']);
        $this->assertEquals('Updated comment', $updatedDetail['properties']['comment']);
        $this->assertEquals('https://example.com/new-wc', $updatedDetail['properties']['website']);
    }

    public function test_add_toilet_source_parameter(): void
    {
        // 1. Explicit source
        $res1 = $this->postJson('/toilet/add', [
            'name' => 'Toilet with Source',
            'lat' => 52.5200,
            'lon' => 13.4050,
            'source' => 'custom_import',
        ]);
        $res1->assertStatus(200);
        $id1 = $res1->json('id');
        $this->createdToiletIds[] = $id1;

        $toilet1 = Toilet::find($id1);
        $this->assertSame('custom_import', $toilet1->source);

        // 2. Default source is null
        $res2 = $this->postJson('/toilet/add', [
            'name' => 'Toilet without Source',
            'lat' => 52.5200,
            'lon' => 13.4050,
        ]);
        $res2->assertStatus(200);
        $id2 = $res2->json('id');
        $this->createdToiletIds[] = $id2;

        $toilet2 = Toilet::find($id2);
        $this->assertNull($toilet2->source);

        // 3. Source exceeding 45 chars fails validation
        $res3 = $this->postJson('/toilet/add', [
            'lat' => 52.5200,
            'lon' => 13.4050,
            'source' => str_repeat('a', 46),
        ]);
        $res3->assertStatus(400);
        $this->assertArrayHasKey('source', $res3->json('errors'));
    }
}
