<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Toilet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UnflagPublicBathroomToiletsCommand extends Command
{
    public const OPEN_24_HOURS_PERIODS = '[{"open":{"day":0,"hour":0,"minute":0}}]';

    protected $signature = 'app:unflag-public-bathrooms-without-opening-hours
                            {--dry-run : Preview changes without modifying the database}
                            {--only-hours : Only match toilets where setting place_opening_hours to null was the only substantive change}
                            {--include-restroom-types : Also consider places with type "restroom" or "toilet" as public bathrooms}';

    protected $aliases = [
        'app:unflag-public-bathroom-toilets',
        'app:unflag-public-bathrooms',
    ];

    protected $description = 'Find flagged toilets where the last update set place_opening_hours to null, unflag them and set 24/7 opening hours if assigned to a place with type "public_bathroom"';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyHours = (bool) $this->option('only-hours');
        $includeRestroomTypes = (bool) $this->option('include-restroom-types');

        if ($dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        } else {
            $this->info('Searching for flagged toilets where the last update set place_opening_hours to null...');
        }

        $flaggedToilets = Toilet::where('flagged', 1)
            ->with(['place', 'latestRevision'])
            ->orderBy('id')
            ->get();

        $totalFlagged = $flaggedToilets->count();

        /** @var array<int, array{toilet: Toilet, place: ?Place, isPublicBathroom: bool, types: array<string>, otherChanges: array<string>}> $matchingToilets */
        $matchingToilets = [];

        foreach ($flaggedToilets as $toilet) {
            $diff = $this->resolveDiff($toilet);

            if (! $this->isLastUpdateSettingOpeningHoursToNull($diff)) {
                continue;
            }

            if ($onlyHours && ! $this->hasOnlyOpeningHoursChange($diff)) {
                continue;
            }

            $place = $toilet->place ?? ($toilet->place_id ? Place::find($toilet->place_id) : null);
            $isPublicBathroom = $this->placeHasPublicBathroomType($place, $includeRestroomTypes);

            $types = [];
            if ($place && is_array($place->data)) {
                $rawTypes = $place->data['types'] ?? [];
                if (is_array($rawTypes)) {
                    $types = array_values(array_filter($rawTypes, 'is_string'));
                }
                if (isset($place->data['primaryType']) && is_string($place->data['primaryType'])) {
                    $types[] = $place->data['primaryType'];
                }
                $types = array_values(array_unique($types));
            }

            $otherChanges = $this->getOtherSubstantiveChanges($diff);

            $matchingToilets[] = [
                'toilet' => $toilet,
                'place' => $place,
                'isPublicBathroom' => $isPublicBathroom,
                'types' => $types,
                'otherChanges' => $otherChanges,
            ];
        }

        $toUnflag = array_values(array_filter($matchingToilets, fn ($item) => $item['isPublicBathroom']));
        $toKeepFlagged = array_values(array_filter($matchingToilets, fn ($item) => ! $item['isPublicBathroom']));

        // Display results table
        if (! empty($matchingToilets)) {
            $tableRows = [];
            foreach ($matchingToilets as $item) {
                /** @var Toilet $t */
                $t = $item['toilet'];
                /** @var ?Place $p */
                $p = $item['place'];
                $isPb = $item['isPublicBathroom'];
                $placeName = $p?->getName() ?? ($t->place_id ? 'Place #'.$t->place_id : 'No place assigned');
                $typesStr = ! empty($item['types']) ? implode(', ', array_slice($item['types'], 0, 3)) : '-';
                $otherStr = ! empty($item['otherChanges']) ? implode(', ', $item['otherChanges']) : 'none';

                if ($isPb) {
                    $status = $dryRun ? '<comment>Would unflag & set open 24hrs</comment>' : '<info>Unflagged & set open 24hrs</info>';
                } else {
                    $status = '<fg=gray>Kept flagged (no public_bathroom type)</>';
                }

                $tableRows[] = [
                    $t->id,
                    $t->name ?: 'Unnamed',
                    $t->place_id ?: '-',
                    $placeName,
                    $typesStr,
                    $isPb ? 'Yes' : 'No',
                    $otherStr,
                    $status,
                ];
            }

            $this->table(
                ['Toilet ID', 'Toilet Name', 'Place ID', 'Place Name', 'Types', 'public_bathroom?', 'Other Changes', 'Action'],
                $tableRows
            );
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total flagged toilets evaluated', $totalFlagged],
                ['Flagged toilets with place_opening_hours set to null', count($matchingToilets)],
                ['  └ With place type "public_bathroom" (to unflag & set open 24hrs)', count($toUnflag)],
                ['  └ Without place type "public_bathroom" (kept flagged)', count($toKeepFlagged)],
            ]
        );

        if (empty($matchingToilets)) {
            $this->info('No flagged toilets found where the last update set place_opening_hours to null.');

            return self::SUCCESS;
        }

        if (empty($toUnflag)) {
            $this->warn('Found '.count($matchingToilets).' flagged toilet(s) where place_opening_hours was set to null, but none of them are assigned to a place with type "public_bathroom".');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Dry-run complete. '.count($toUnflag).' toilet(s) would be unflagged and set to 24/7 opening hours.');

            return self::SUCCESS;
        }

        // Apply unflag update
        $unflagIds = array_map(fn ($item) => $item['toilet']->id, $toUnflag);
        Toilet::whereIn('id', $unflagIds)->update(['flagged' => false]);

        // Set place_opening_hours to 24/7 opening hours
        $openingHoursRows = array_map(fn ($id) => [
            'fk_toiletId' => $id,
            'type' => 'place_opening_hours',
            'value' => self::OPEN_24_HOURS_PERIODS,
            'user_overridden' => 1,
        ], $unflagIds);

        DB::table('toilet_properties')->upsert($openingHoursRows, ['fk_toiletId', 'type'], ['value', 'user_overridden']);

        $this->info('Successfully unflagged and set 24/7 opening hours for '.count($toUnflag).' toilet(s) assigned to places of type "public_bathroom".');

        return self::SUCCESS;
    }

    /**
     * Resolve the diff array representing the toilet's last update.
     */
    public function resolveDiff(Toilet $toilet): array
    {
        $diff = $toilet->last_diff;
        if (is_string($diff)) {
            $diff = json_decode($diff, true);
        }

        if (empty($diff) && $toilet->latestRevision && ! empty($toilet->latestRevision->diff)) {
            $diff = $toilet->latestRevision->diff;
        }

        if (is_string($diff)) {
            $diff = json_decode($diff, true);
        }

        return is_array($diff) ? $diff : [];
    }

    /**
     * Check if the last update diff contains place_opening_hours set to null.
     */
    public function isLastUpdateSettingOpeningHoursToNull(array $diff): bool
    {
        if (empty($diff) || ! array_key_exists('place_opening_hours', $diff)) {
            return false;
        }

        $change = $diff['place_opening_hours'];

        if ($change === null) {
            return true;
        }

        if (is_array($change)) {
            if (array_key_exists('new', $change)) {
                $newVal = $change['new'];

                return $newVal === null || $newVal === '';
            }
        }

        return false;
    }

    /**
     * Check if setting place_opening_hours to null was the only substantive change in the diff.
     */
    public function hasOnlyOpeningHoursChange(array $diff): bool
    {
        $substantiveChanges = $this->getOtherSubstantiveChanges($diff);

        return empty($substantiveChanges);
    }

    /**
     * Return list of other substantive changed keys in the diff.
     *
     * @return array<int, string>
     */
    public function getOtherSubstantiveChanges(array $diff): array
    {
        $ignoredKeys = [
            'place_opening_hours',
            'flagged',
            'email_sent',
            'updated',
            'last_diff',
            'last_places_fetch',
            'last_discovered',
            'last_crawled',
            'last_included',
        ];

        $otherChanges = [];

        foreach ($diff as $key => $change) {
            if (in_array($key, $ignoredKeys, true)) {
                continue;
            }

            if (is_array($change)) {
                $cOld = $change['old'] ?? null;
                $cNew = $change['new'] ?? null;
                if ($cOld === $cNew || (string) $cOld === (string) $cNew) {
                    continue; // No-op change
                }
            }

            $otherChanges[] = (string) $key;
        }

        return $otherChanges;
    }

    /**
     * Check if a Place has type 'public_bathroom' (or optionally 'restroom'/'toilet').
     */
    public function placeHasPublicBathroomType(?Place $place, bool $includeRestroomTypes = false): bool
    {
        if (! $place) {
            return false;
        }

        $data = $place->data;
        if (! is_array($data)) {
            return false;
        }

        $types = $data['types'] ?? [];
        if (! is_array($types)) {
            $types = [];
        }

        $primaryType = $data['primaryType'] ?? null;

        $targetTypes = ['public_bathroom'];
        if ($includeRestroomTypes) {
            $targetTypes = array_merge($targetTypes, ['restroom', 'toilet']);
        }

        foreach ($targetTypes as $t) {
            if (in_array($t, $types, true) || $primaryType === $t) {
                return true;
            }
        }

        return false;
    }
}
