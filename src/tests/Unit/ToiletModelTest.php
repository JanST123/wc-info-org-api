<?php

namespace Tests\Unit;

use App\Models\Toilet;
use Tests\TestCase;

class ToiletModelTest extends TestCase
{
    public function test_flag_helpers_return_false_without_properties(): void
    {
        $toilet = new Toilet;

        $this->assertFalse($toilet->isFlagSet('is_unisex'));
        $this->assertFalse($toilet->isFlagSet('has_wheelchair_access'));
        $this->assertFalse($toilet->isFlagSet('nonexistent_flag'));
    }

    public function test_flag_helper_returns_true_when_property_value_is_one(): void
    {
        $toilet = new Toilet;
        $toilet->setRelation('properties', collect([
            (object) ['type' => 'is_unisex', 'value' => '1'],
            (object) ['type' => 'has_changing_table', 'value' => '1'],
        ]));

        $this->assertTrue($toilet->isFlagSet('is_unisex'));
        $this->assertTrue($toilet->isFlagSet('has_changing_table'));
        $this->assertFalse($toilet->isFlagSet('is_gender_separated'));
    }

    public function test_property_value_returns_first_match(): void
    {
        $toilet = new Toilet;
        $toilet->setRelation('properties', collect([
            (object) ['type' => 'address', 'value' => 'Main St 1'],
            (object) ['type' => 'website', 'value' => 'https://example.com'],
        ]));

        $this->assertSame('Main St 1', $toilet->propertyValue('address'));
        $this->assertSame('https://example.com', $toilet->propertyValue('website'));
        $this->assertNull($toilet->propertyValue('comment'));
    }

    public function test_coordinates_are_rounded_and_compared_with_tolerance(): void
    {
        $toilet = new Toilet;
        $toilet->lat = 52.52000000000001;
        $toilet->lon = 7.409111399999999;

        $this->assertSame(52.52, $toilet->lat);
        $this->assertSame(7.4091114, $toilet->lon);

        $this->assertTrue(Toilet::areCoordinatesEqual(7.4091114, 7.409111399999999));
        $this->assertTrue(Toilet::areCoordinatesEqual(52.52, 52.52000000000001));
        $this->assertTrue(Toilet::areCoordinatesEqual(null, null));
        $this->assertFalse(Toilet::areCoordinatesEqual(7.4091114, null));
        $this->assertFalse(Toilet::areCoordinatesEqual(null, 7.4091114));
        $this->assertFalse(Toilet::areCoordinatesEqual(7.4091114, 7.4092114));
    }
}
