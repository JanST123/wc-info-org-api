<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\UnflagPublicBathroomToiletsCommand;
use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletRevision;
use PHPUnit\Framework\TestCase;

class UnflagPublicBathroomToiletsCommandUnitTest extends TestCase
{
    private UnflagPublicBathroomToiletsCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new UnflagPublicBathroomToiletsCommand;
    }

    public function test_open_24_hours_periods_constant(): void
    {
        $this->assertSame(
            '[{"open":{"day":0,"hour":0,"minute":0}}]',
            UnflagPublicBathroomToiletsCommand::OPEN_24_HOURS_PERIODS
        );
        $decoded = json_decode(UnflagPublicBathroomToiletsCommand::OPEN_24_HOURS_PERIODS, true);
        $this->assertIsArray($decoded);
        $this->assertSame(0, $decoded[0]['open']['day']);
        $this->assertSame(0, $decoded[0]['open']['hour']);
        $this->assertSame(0, $decoded[0]['open']['minute']);
    }

    public function test_is_last_update_setting_opening_hours_to_null_detects_null(): void
    {
        $this->assertTrue($this->command->isLastUpdateSettingOpeningHoursToNull([
            'place_opening_hours' => [
                'old' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
                'new' => null,
            ],
        ]));

        $this->assertTrue($this->command->isLastUpdateSettingOpeningHoursToNull([
            'place_opening_hours' => [
                'old' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
                'new' => '',
            ],
        ]));

        $this->assertTrue($this->command->isLastUpdateSettingOpeningHoursToNull([
            'place_opening_hours' => null,
        ]));
    }

    public function test_is_last_update_setting_opening_hours_to_null_returns_false_when_not_null_or_missing(): void
    {
        // When new has a value
        $this->assertFalse($this->command->isLastUpdateSettingOpeningHoursToNull([
            'place_opening_hours' => [
                'old' => null,
                'new' => '[{"open":{"day":1,"hour":8,"minute":0}}]',
            ],
        ]));

        // When key is missing
        $this->assertFalse($this->command->isLastUpdateSettingOpeningHoursToNull([
            'address' => [
                'old' => 'A',
                'new' => null,
            ],
        ]));

        $this->assertFalse($this->command->isLastUpdateSettingOpeningHoursToNull([]));
    }

    public function test_has_only_opening_hours_change_ignores_metadata_keys(): void
    {
        $diff = [
            'place_opening_hours' => [
                'old' => '...',
                'new' => null,
            ],
            'flagged' => [
                'old' => false,
                'new' => true,
            ],
            'email_sent' => [
                'old' => 0,
                'new' => 1,
            ],
            'last_places_fetch' => [
                'old' => null,
                'new' => '2026-10-29 09:00:00',
            ],
            'last_discovered' => [
                'old' => null,
                'new' => '2026-10-29 09:00:00',
            ],
        ];

        $this->assertTrue($this->command->hasOnlyOpeningHoursChange($diff));
        $this->assertEmpty($this->command->getOtherSubstantiveChanges($diff));
    }

    public function test_has_only_opening_hours_change_detects_substantive_changes(): void
    {
        $diff = [
            'place_opening_hours' => [
                'old' => '...',
                'new' => null,
            ],
            'address' => [
                'old' => 'Old street 1',
                'new' => 'New street 2',
            ],
        ];

        $this->assertFalse($this->command->hasOnlyOpeningHoursChange($diff));
        $this->assertSame(['address'], $this->command->getOtherSubstantiveChanges($diff));
    }

    public function test_place_has_public_bathroom_type(): void
    {
        $this->assertFalse($this->command->placeHasPublicBathroomType(null));

        $pbPlace = new Place([
            'data' => [
                'types' => ['public_bathroom', 'point_of_interest'],
            ],
        ]);
        $this->assertTrue($this->command->placeHasPublicBathroomType($pbPlace));

        $primaryPbPlace = new Place([
            'data' => [
                'types' => ['point_of_interest'],
                'primaryType' => 'public_bathroom',
            ],
        ]);
        $this->assertTrue($this->command->placeHasPublicBathroomType($primaryPbPlace));

        $restaurantPlace = new Place([
            'data' => [
                'types' => ['restaurant', 'food'],
            ],
        ]);
        $this->assertFalse($this->command->placeHasPublicBathroomType($restaurantPlace));

        $restroomPlace = new Place([
            'data' => [
                'types' => ['restroom'],
            ],
        ]);
        $this->assertFalse($this->command->placeHasPublicBathroomType($restroomPlace, false));
        $this->assertTrue($this->command->placeHasPublicBathroomType($restroomPlace, true));
    }

    public function test_resolve_diff_prefers_last_diff_and_falls_back_to_revision(): void
    {
        // 1. Array in last_diff
        $toilet1 = new Toilet;
        $toilet1->last_diff = ['place_opening_hours' => ['old' => 'a', 'new' => null]];
        $this->assertSame(
            ['place_opening_hours' => ['old' => 'a', 'new' => null]],
            $this->command->resolveDiff($toilet1)
        );

        // 2. JSON string in last_diff
        $toilet2 = new Toilet;
        $toilet2->setRawAttributes(['last_diff' => json_encode(['place_opening_hours' => null])]);
        $this->assertSame(
            ['place_opening_hours' => null],
            $this->command->resolveDiff($toilet2)
        );

        // 3. Fallback to latestRevision
        $toilet3 = new Toilet;
        $revision = new ToiletRevision;
        $revision->diff = ['place_opening_hours' => ['old' => 'x', 'new' => null]];
        $toilet3->setRelation('latestRevision', $revision);
        $this->assertSame(
            ['place_opening_hours' => ['old' => 'x', 'new' => null]],
            $this->command->resolveDiff($toilet3)
        );
    }
}
