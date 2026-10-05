<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Place;
use Tests\TestCase;

class PlaceModelTest extends TestCase
{
    public function test_get_emoji_for_types_base(): void
    {
        $this->assertSame('☕', Place::getEmojiForTypes(['cafe']));
        $this->assertSame('🍽️', Place::getEmojiForTypes(['restaurant']));
        $this->assertSame('🚽', Place::getEmojiForTypes(['public_bathroom']));
        $this->assertSame('🚉', Place::getEmojiForTypes(['train_station']));
        $this->assertSame('', Place::getEmojiForTypes([]));
        $this->assertSame('', Place::getEmojiForTypes(['unknown_type']));
    }

    public function test_get_emoji_for_types_with_restroom_and_wheelchair_flags(): void
    {
        // 1. Type + restroom
        $this->assertSame('☕🚾', Place::getEmojiForTypes(['cafe'], hasRestroom: true));

        // 2. Type + wheelchair restroom
        $this->assertSame('☕♿️', Place::getEmojiForTypes(['cafe'], hasWheelchairRestroom: true));

        // 3. Type + restroom + wheelchair restroom
        $this->assertSame('☕🚾♿️', Place::getEmojiForTypes(['cafe'], hasRestroom: true, hasWheelchairRestroom: true));

        // 4. No matching type + restroom
        $this->assertSame('🚾', Place::getEmojiForTypes([], hasRestroom: true));

        // 5. No matching type + restroom + wheelchair restroom
        $this->assertSame('🚾♿️', Place::getEmojiForTypes([], hasRestroom: true, hasWheelchairRestroom: true));

        // 6. Public bathroom + restroom + wheelchair restroom
        $this->assertSame('🚽🚾♿️', Place::getEmojiForTypes(['public_bathroom'], hasRestroom: true, hasWheelchairRestroom: true));
    }

    public function test_get_emoji_for_place_data_extracts_nested_flags(): void
    {
        // 1. Place data with restroom = true
        $data1 = [
            'types' => ['cafe'],
            'restroom' => true,
        ];
        $this->assertSame('☕🚾', Place::getEmojiForPlaceData($data1));

        // 2. Place data with accessibilityOptions.wheelchairAccessibleRestroom = true
        $data2 = [
            'types' => ['restaurant'],
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
        ];
        $this->assertSame('🍽️♿️', Place::getEmojiForPlaceData($data2));

        // 3. Place data with both restroom and wheelchairAccessibleRestroom
        $data3 = [
            'types' => ['bar'],
            'restroom' => true,
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
        ];
        $this->assertSame('🍺🚾♿️', Place::getEmojiForPlaceData($data3));
    }

    public function test_place_model_get_emoji(): void
    {
        $place = new Place([
            'place_id' => 'test_place_model_emoji',
            'data' => [
                'types' => ['bakery'],
                'hasRestroom' => true,
                'accessibilityOptions' => [
                    'wheelchairAccessibleRestroom' => true,
                ],
            ],
        ]);

        $this->assertSame('☕🚾♿️', $place->getEmoji());
    }
}
