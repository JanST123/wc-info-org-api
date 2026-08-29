<?php

namespace Tests\Unit;

use App\Http\Resources\ToiletDetailResource;
use App\Http\Resources\ToiletListResource;
use App\Models\Toilet;
use Illuminate\Http\Request;
use Tests\TestCase;

class ToiletResourceTest extends TestCase
{
    public function test_resource_includes_expected_v2_fields(): void
    {
        $toilet = new Toilet([
            'id' => 42,
            'name' => 'Test Toilet',
            'owner' => 'City',
            'status' => 'active',
            'is_qualified' => true,
            'lat' => 52.5200,
            'lon' => 13.4050,
            'place_id' => 'ChIJtest',
        ]);

        $toilet->setRelation('properties', collect([
            (object) ['type' => 'address', 'value' => 'Test Street 1'],
            (object) ['type' => 'website', 'value' => 'https://example.com'],
            (object) ['type' => 'is_unisex', 'value' => '1'],
        ]));
        $toilet->setRelation('photos', collect());

        $resource = new ToiletDetailResource($toilet);
        $array = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('id', $array);
        $this->assertSame('Test Toilet', $array['name']);
        $this->assertSame('active', $array['status']);
        $this->assertTrue($array['is_qualified']);
        $this->assertSame(52.5200, $array['lat']);
        $this->assertSame(13.4050, $array['lon']);
        $this->assertSame('Test Street 1', $array['properties']['address']);
        $this->assertSame('https://example.com', $array['properties']['website']);
        $this->assertTrue($array['flags']['is_unisex']);

        // Legacy fields must be absent.
        $this->assertArrayNotHasKey('type', $array);
        $this->assertArrayNotHasKey('nr', $array);
    }

    public function test_list_resource_includes_comment_and_website(): void
    {
        $toilet = new Toilet([
            'id' => 101,
            'name' => 'List Toilet',
            'owner' => 'Station',
            'status' => 'active',
            'is_qualified' => false,
            'lat' => 52.5200,
            'lon' => 13.4050,
        ]);

        $toilet->setRelation('properties', collect([
            (object) ['type' => 'address', 'value' => 'Bahnhofstr. 5'],
            (object) ['type' => 'comment', 'value' => 'Behind platform 2'],
            (object) ['type' => 'website', 'value' => 'https://station-wc.example.com'],
            (object) ['type' => 'euro_key', 'value' => 'yes'],
            (object) ['type' => 'storage_space', 'value' => 'little'],
        ]));
        $toilet->setRelation('photos', collect());

        $resource = new ToiletListResource($toilet);
        $array = $resource->toArray(Request::create('/'));

        $this->assertSame('Bahnhofstr. 5', $array['address']);
        $this->assertSame('Behind platform 2', $array['comment']);
        $this->assertSame('https://station-wc.example.com', $array['website']);
        $this->assertSame('yes', $array['euro_key']);
        $this->assertSame('little', $array['storage_space']);
    }
}
