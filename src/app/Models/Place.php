<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    use HasFactory;

    protected $table = 'places';

    public $timestamps = false;

    protected $primaryKey = 'place_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'place_id',
        'data',
        'updated',
    ];

    protected $casts = [
        'data' => 'array',
        'updated' => 'datetime',
    ];

    public function getName(): ?string
    {
        $data = $this->data;
        if (! is_array($data)) {
            return null;
        }

        return $data['displayName']['text']
            ?? (is_string($data['displayName'] ?? null) ? $data['displayName'] : null)
            ?? (! str_starts_with((string) ($data['name'] ?? ''), 'places/') ? ($data['name'] ?? null) : null)
            ?? ($data['formattedAddress'] ?? $data['formatted_address'] ?? null);
    }

    public function getLat(): ?float
    {
        $data = $this->data;
        if (! is_array($data)) {
            return null;
        }

        $lat = $data['location']['latitude'] ?? $data['location']['lat'] ?? $data['geometry']['location']['lat'] ?? null;

        return $lat !== null ? round((float) $lat, 7) : null;
    }

    public function getLon(): ?float
    {
        $data = $this->data;
        if (! is_array($data)) {
            return null;
        }

        $lon = $data['location']['longitude'] ?? $data['location']['lng'] ?? $data['geometry']['location']['lng'] ?? null;

        return $lon !== null ? round((float) $lon, 7) : null;
    }

    /**
     * Map place types to descriptive emojis (e.g. 🚽 for toilets, 🚉 for stations, 🍽️ for restaurants, ☕ for cafes),
     * optionally followed by 🚾 if restroom is true and ♿️ if wheelchairAccessibleRestroom is true.
     *
     * @param  array<int, string>|null  $types
     */
    public static function getEmojiForTypes(?array $types, bool $hasRestroom = false, bool $hasWheelchairRestroom = false): string
    {
        $emoji = '';
        if (! empty($types)) {
            $typesMap = [
                'public_bathroom' => '🚽',
                'restroom' => '🚽',
                'toilet' => '🚽',
                'train_station' => '🚉',
                'subway_station' => '🚉',
                'transit_station' => '🚉',
                'light_rail_station' => '🚉',
                'railway_station' => '🚉',
                'bus_station' => '🚌',
                'bus_stop' => '🚌',
                'airport' => '✈️',
                'restaurant' => '🍽️',
                'fast_food_restaurant' => '🍽️',
                'meal_takeaway' => '🍽️',
                'meal_delivery' => '🍽️',
                'food_court' => '🍽️',
                'cafe' => '☕',
                'coffee_shop' => '☕',
                'bakery' => '☕',
                'bar' => '🍺',
                'pub' => '🍺',
                'night_club' => '🍺',
                'park' => '🌳',
                'campground' => '🌳',
                'garden' => '🌳',
                'national_park' => '🌳',
                'supermarket' => '🛒',
                'grocery_store' => '🛒',
                'shopping_mall' => '🛒',
                'department_store' => '🛒',
                'convenience_store' => '🛒',
                'store' => '🛒',
                'gas_station' => '⛽',
                'electric_vehicle_charging_station' => '⛽',
                'lodging' => '🏨',
                'hotel' => '🏨',
                'motel' => '🏨',
                'hospital' => '🏥',
                'doctor' => '🏥',
                'pharmacy' => '🏥',
                'parking' => '🅿️',
                'museum' => '🏛️',
                'art_gallery' => '🏛️',
                'library' => '🏛️',
                'tourist_attraction' => '🏛️',
                'place_of_worship' => '🏛️',
                'church' => '🏛️',
                'city_hall' => '🏛️',
                'town_hall' => '🏛️',
                'stadium' => '🏟️',
                'sports_complex' => '🏟️',
                'gym' => '🏟️',
                'playground' => '🎡',
                'amusement_park' => '🎡',
            ];

            foreach ($typesMap as $type => $icon) {
                if (in_array($type, $types, true)) {
                    $emoji = $icon;
                    break;
                }
            }
        }

        if ($hasRestroom) {
            $emoji .= '🚾';
        }

        if ($hasWheelchairRestroom) {
            $emoji .= '♿️';
        }

        return $emoji;
    }

    /**
     * Get fitting emoji for a Place instance or place data array based on types and restroom accessibility.
     *
     * @param  array<string, mixed>|Place|null  $place
     */
    public static function getEmojiForPlaceData(array|Place|null $place): string
    {
        if ($place instanceof Place) {
            $data = is_array($place->data) ? $place->data : [];
        } elseif (is_array($place)) {
            $data = $place;
        } else {
            return '';
        }

        $types = $data['types'] ?? [];
        if (! is_array($types)) {
            $types = [];
        }

        $hasRestroom = ! empty($data['restroom']) || ! empty($data['hasRestroom']);
        $hasWheelchairRestroom = ! empty($data['accessibilityOptions']['wheelchairAccessibleRestroom'])
            || ! empty($data['accessibility_options']['wheelchair_accessible_restroom'])
            || ! empty($data['wheelchairAccessibleRestroom']);

        return self::getEmojiForTypes($types, $hasRestroom, $hasWheelchairRestroom);
    }

    /**
     * Get fitting emoji for this Place instance based on cached types and restroom flags.
     */
    public function getEmoji(): string
    {
        return self::getEmojiForPlaceData(is_array($this->data) ? $this->data : []);
    }
}
