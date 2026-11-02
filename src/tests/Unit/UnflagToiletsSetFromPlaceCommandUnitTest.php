<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\UnflagToiletsSetFromPlaceCommand;
use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletRevision;
use PHPUnit\Framework\TestCase;

class UnflagToiletsSetFromPlaceCommandUnitTest extends TestCase
{
    private UnflagToiletsSetFromPlaceCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new UnflagToiletsSetFromPlaceCommand;
    }

    public function test_is_old_value_null_or_unset(): void
    {
        // Text / attribute fields
        $this->assertTrue($this->command->isOldValueNullOrUnset('owner', null));
        $this->assertTrue($this->command->isOldValueNullOrUnset('owner', ''));
        $this->assertFalse($this->command->isOldValueNullOrUnset('owner', 'Previous Owner'));
        $this->assertFalse($this->command->isOldValueNullOrUnset('owner', '0'));

        $this->assertTrue($this->command->isOldValueNullOrUnset('address', null));
        $this->assertTrue($this->command->isOldValueNullOrUnset('address', ''));
        $this->assertFalse($this->command->isOldValueNullOrUnset('address', 'Old Str. 1'));

        $this->assertTrue($this->command->isOldValueNullOrUnset('website', null));
        $this->assertFalse($this->command->isOldValueNullOrUnset('website', 'https://old.com'));

        // Boolean flag fields
        $this->assertTrue($this->command->isOldValueNullOrUnset('is_unisex', null));
        $this->assertTrue($this->command->isOldValueNullOrUnset('is_unisex', ''));
        $this->assertTrue($this->command->isOldValueNullOrUnset('is_unisex', '0'));
        $this->assertTrue($this->command->isOldValueNullOrUnset('is_unisex', 0));
        $this->assertTrue($this->command->isOldValueNullOrUnset('is_unisex', false));
        $this->assertFalse($this->command->isOldValueNullOrUnset('is_unisex', '1'));
        $this->assertFalse($this->command->isOldValueNullOrUnset('is_unisex', true));

        $this->assertTrue($this->command->isOldValueNullOrUnset('has_wheelchair_access', '0'));
        $this->assertTrue($this->command->isOldValueNullOrUnset('public_accessible', null));
        $this->assertTrue($this->command->isOldValueNullOrUnset('temporary_closed', null));
    }

    public function test_is_coordinate_not_a_float(): void
    {
        // Null / empty / non-numeric
        $this->assertTrue($this->command->isCoordinateNotAFloat(null));
        $this->assertTrue($this->command->isCoordinateNotAFloat(''));
        $this->assertTrue($this->command->isCoordinateNotAFloat('invalid'));

        // Zero values
        $this->assertTrue($this->command->isCoordinateNotAFloat(0));
        $this->assertTrue($this->command->isCoordinateNotAFloat('0'));
        $this->assertTrue($this->command->isCoordinateNotAFloat(0.0));
        $this->assertTrue($this->command->isCoordinateNotAFloat('0.00000000'));

        // Whole integers / numbers without decimal precision
        $this->assertTrue($this->command->isCoordinateNotAFloat(52));
        $this->assertTrue($this->command->isCoordinateNotAFloat('52'));
        $this->assertTrue($this->command->isCoordinateNotAFloat(52.0));
        $this->assertTrue($this->command->isCoordinateNotAFloat('52.00000000'));
        $this->assertTrue($this->command->isCoordinateNotAFloat(13));
        $this->assertTrue($this->command->isCoordinateNotAFloat('13.00000000'));

        // Real coordinates with float decimal precision
        $this->assertFalse($this->command->isCoordinateNotAFloat(52.520008));
        $this->assertFalse($this->command->isCoordinateNotAFloat('52.520008'));
        $this->assertFalse($this->command->isCoordinateNotAFloat(13.404954));
        $this->assertFalse($this->command->isCoordinateNotAFloat('13.404954'));
        $this->assertFalse($this->command->isCoordinateNotAFloat(52.1));
    }

    public function test_is_field_matching_current_place_data(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ1234567890';
        $place->data = [
            'id' => 'ChIJ1234567890',
            'displayName' => ['text' => 'Central Cafe'],
            'formattedAddress' => 'Alexanderplatz 1, 10178 Berlin',
            'websiteUri' => 'https://central-cafe.berlin/',
            'location' => [
                'latitude' => 52.520008,
                'longitude' => 13.404954,
            ],
            'types' => ['cafe', 'food', 'point_of_interest'],
            'businessStatus' => 'OPERATIONAL',
            'restroom' => true,
            'accessibilityOptions' => [
                'wheelchairAccessibleRestroom' => true,
            ],
            'regularOpeningHours' => [
                'periods' => [
                    [
                        'open' => ['day' => 1, 'hour' => 8, 'minute' => 0],
                        'close' => ['day' => 1, 'hour' => 20, 'minute' => 0],
                    ],
                ],
            ],
        ];

        // Owner matching
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('owner', 'Central Cafe', $place));
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('owner', ' Central Cafe ', $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('owner', 'Different Cafe', $place));

        // Address matching
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('address', 'Alexanderplatz 1, 10178 Berlin', $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('address', 'Wrong Address', $place));

        // Website matching (including trailing slash tolerance)
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('website', 'https://central-cafe.berlin/', $place));
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('website', 'https://central-cafe.berlin', $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('website', 'https://other-site.com', $place));

        // Coordinates matching
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('lat', 52.520008, $place));
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('lon', 13.404954, $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('lat', 48.1351, $place));

        // Place ID
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('place_id', 'ChIJ1234567890', $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('place_id', 'ChIJ9999999999', $place));

        // Opening hours matching
        $expectedJson = '[{"open":{"day":1,"hour":8,"minute":0},"close":{"day":1,"hour":20,"minute":0}}]';
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('place_opening_hours', $expectedJson, $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('place_opening_hours', '[{"open":{"day":0,"hour":0,"minute":0}}]', $place));

        // Flags derived from place data
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('is_unisex', '1', $place));
        $this->assertTrue($this->command->isFieldMatchingCurrentPlaceData('has_wheelchair_access', '1', $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('temporary_closed', '1', $place)); // place is OPERATIONAL

        // Unknown / non-place fields
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('comment', 'Some comment', $place));
        $this->assertFalse($this->command->isFieldMatchingCurrentPlaceData('euro_key', '1', $place));
    }

    public function test_is_last_change_only_set_from_null_to_place_data_accepts_valid_diff(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'Park Cafe'],
            'formattedAddress' => 'Parkweg 5, Berlin',
            'websiteUri' => 'https://parkcafe.de',
            'types' => ['cafe'],
            'restroom' => true,
        ];

        $diff = [
            'owner' => ['old' => null, 'new' => 'Park Cafe'],
            'address' => ['old' => null, 'new' => 'Parkweg 5, Berlin'],
            'website' => ['old' => null, 'new' => 'https://parkcafe.de'],
            'is_unisex' => ['old' => '0', 'new' => '1'],
            'flagged' => ['old' => false, 'new' => true],
            'last_diff' => ['old' => null, 'new' => '...'],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertTrue($result);
        $this->assertSame(['owner', 'address', 'website', 'is_unisex'], $matchedFields);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_accepts_owner_updated_to_place_name_from_non_null(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'Updated Place Name'],
        ];

        // owner old value was NOT null ('Old Previous Owner'), but new is the current place name -> MUST BE UNFLAGGED
        $diff = [
            'owner' => ['old' => 'Old Previous Owner', 'new' => 'Updated Place Name'],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertTrue($result);
        $this->assertSame(['owner'], $matchedFields);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_accepts_website_set_to_null(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'Some Place'],
        ];

        // website old was an existing URL, new was set to null -> MUST BE UNFLAGGED
        $diff = [
            'website' => ['old' => 'https://broken-or-stale-website.com', 'new' => null],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertTrue($result);
        $this->assertSame(['website'], $matchedFields);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_accepts_combined_owner_and_website_null_and_null_to_place_fields(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'Grand Place'],
            'formattedAddress' => 'Grand Street 1, Berlin',
        ];

        $diff = [
            'owner' => ['old' => 'Old Name', 'new' => 'Grand Place'],
            'website' => ['old' => 'https://old-site.com', 'new' => null],
            'address' => ['old' => null, 'new' => 'Grand Street 1, Berlin'],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertTrue($result);
        $this->assertSame(['owner', 'website', 'address'], $matchedFields);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_accepts_coordinates_when_old_not_a_float(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'location' => [
                'latitude' => 52.520008,
                'longitude' => 13.404954,
            ],
        ];

        // Old lat/lon were integer whole numbers (not proper floats) -> MUST BE UNFLAGGED
        $diff = [
            'lat' => ['old' => 52, 'new' => 52.520008],
            'lon' => ['old' => 13, 'new' => 13.404954],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertTrue($result);
        $this->assertSame(['lat', 'lon'], $matchedFields);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_rejects_coordinates_when_old_was_already_a_float(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'location' => [
                'latitude' => 52.520008,
                'longitude' => 13.404954,
            ],
        ];

        // Old lat/lon were already valid floats with decimals (meaning coordinates were intentionally moved) -> MUST NOT UNFLAG
        $diff = [
            'lat' => ['old' => 52.519000, 'new' => 52.520008],
            'lon' => ['old' => 13.404000, 'new' => 13.404954],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertFalse($result);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_accepts_address_updated_to_place_address_from_non_null(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'New Cafe'],
            'formattedAddress' => 'Parkweg 5, Berlin',
        ];

        // address old value was NOT null ('Old Street 1'), but new matches place address -> MUST BE UNFLAGGED
        $diff = [
            'address' => ['old' => 'Old Street 1', 'new' => 'Parkweg 5, Berlin'],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertTrue($result);
        $this->assertSame(['address'], $matchedFields);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_rejects_address_when_new_value_mismatches_place(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'New Cafe'],
            'formattedAddress' => 'Parkweg 5, Berlin',
        ];

        // address new does not match place address -> MUST NOT UNFLAG
        $diff = [
            'address' => ['old' => 'Old Street 1', 'new' => 'Wrong Other Street 99'],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertFalse($result);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_accepts_any_website_change(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'Some Place'],
        ];

        // website changed from one URL to another URL -> website change is always allowed -> MUST BE UNFLAGGED
        $diff = [
            'website' => ['old' => 'https://previous-url.com', 'new' => 'https://updated-arbitrary-url.com'],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertTrue($result);
        $this->assertSame(['website'], $matchedFields);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_rejects_when_other_fields_old_not_null(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'New Cafe'],
            'regularOpeningHours' => [
                'periods' => [
                    ['open' => ['day' => 1, 'hour' => 8, 'minute' => 0]],
                ],
            ],
        ];

        // opening hours old was already non-null -> MUST NOT UNFLAG
        $diff = [
            'place_opening_hours' => [
                'old' => '[{"open":{"day":0,"hour":8,"minute":0}}]',
                'new' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
            ],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertFalse($result);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_rejects_when_new_value_mismatches_place(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = [
            'displayName' => ['text' => 'Actual Place Name'],
        ];

        $diff = [
            'owner' => ['old' => null, 'new' => 'Different Arbitrary Name'],
            'flagged' => ['old' => false, 'new' => true],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertFalse($result);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_rejects_when_no_substantive_changes(): void
    {
        $place = new Place;
        $place->place_id = 'ChIJ123';
        $place->data = ['displayName' => ['text' => 'Some Place']];

        // Only metadata keys, no substantive changes
        $diff = [
            'flagged' => ['old' => false, 'new' => true],
            'email_sent' => ['old' => 0, 'new' => 1],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields);

        $this->assertFalse($result);
    }

    public function test_is_last_change_only_set_from_null_to_place_data_rejects_when_place_is_null(): void
    {
        $diff = [
            'owner' => ['old' => null, 'new' => 'Some Name'],
        ];

        $matchedFields = [];
        $result = $this->command->isLastChangeOnlySetFromNullToPlaceData($diff, null, $matchedFields);

        $this->assertFalse($result);
    }

    public function test_resolve_diff_prefers_latest_revision(): void
    {
        $toilet = new Toilet;
        $toilet->last_diff = ['from_last_diff' => ['old' => null, 'new' => 'A']];

        $revision = new ToiletRevision;
        $revision->diff = ['from_revision' => ['old' => null, 'new' => 'B']];
        $toilet->setRelation('latestRevision', $revision);

        $diff = $this->command->resolveDiff($toilet);
        $this->assertArrayHasKey('from_revision', $diff);
        $this->assertArrayNotHasKey('from_last_diff', $diff);
    }

    public function test_resolve_diff_falls_back_to_last_diff(): void
    {
        $toilet = new Toilet;
        $toilet->last_diff = ['from_last_diff' => ['old' => null, 'new' => 'A']];
        $toilet->setRelation('latestRevision', null);

        $diff = $this->command->resolveDiff($toilet);
        $this->assertArrayHasKey('from_last_diff', $diff);
    }
}
