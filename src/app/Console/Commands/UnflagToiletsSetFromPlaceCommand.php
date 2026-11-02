<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Toilet;
use App\Services\PlaceToiletService;
use Illuminate\Console\Command;

class UnflagToiletsSetFromPlaceCommand extends Command
{
    public const IGNORED_DIFF_KEYS = [
        'flagged',
        'email_sent',
        'updated',
        'last_diff',
        'last_places_fetch',
        'last_discovered',
        'last_crawled',
        'last_included',
        'version',
        'source',
    ];

    protected $signature = 'app:unflag-toilets-set-from-place
                            {--dry-run : Preview changes without modifying the database}
                            {--chunk-size=500 : Number of toilets to process per chunk}';

    protected $aliases = [
        'app:unflag-toilets-from-place-data',
        'app:unflag-place-populated-toilets',
    ];

    protected $description = 'Find flagged toilets where the last update only set fields from null to current place data values, set website to null, updated owner to current place name, or fixed non-float coordinates to place coordinates, and unflag them';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(10, (int) $this->option('chunk-size'));

        if ($dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        } else {
            $this->info('Searching for flagged toilets whose last revision only set fields from null to place data, website to null, owner to place name, or fixed non-float coordinates...');
        }

        $flaggedToilets = Toilet::where('flagged', 1)
            ->with(['place', 'latestRevision'])
            ->orderBy('id')
            ->get();

        $totalFlagged = $flaggedToilets->count();

        /** @var array<int, array{toilet: Toilet, place: Place, fields: array<int, string>}> $matching */
        $matching = [];
        /** @var array<int, Toilet> $skipped */
        $skipped = [];

        foreach ($flaggedToilets as $toilet) {
            $diff = $this->resolveDiff($toilet);
            $place = $toilet->place ?? ($toilet->place_id ? Place::find($toilet->place_id) : null);
            $matchedFields = [];

            if ($this->isLastChangeOnlySetFromNullToPlaceData($diff, $place, $matchedFields)) {
                $matching[] = [
                    'toilet' => $toilet,
                    'place' => $place,
                    'fields' => $matchedFields,
                ];
            } else {
                $skipped[] = $toilet;
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total flagged toilets checked', $totalFlagged],
                [$dryRun ? 'Toilets to unflag (dry-run)' : 'Toilets to unflag', count($matching)],
                ['Toilets kept flagged', count($skipped)],
            ]
        );

        if (! empty($matching)) {
            $sampleRows = [];
            $displayLimit = 50;
            foreach (array_slice($matching, 0, $displayLimit) as $item) {
                /** @var Toilet $t */
                $t = $item['toilet'];
                /** @var Place $p */
                $p = $item['place'];
                $sampleRows[] = [
                    $t->id,
                    $p->place_id,
                    mb_strimwidth($p->getName() ?? '-', 0, 30, '...'),
                    implode(', ', $item['fields']),
                ];
            }

            $this->newLine();
            $this->info('Matching toilets to unflag'.(count($matching) > $displayLimit ? " (showing first {$displayLimit})" : '').':');
            $this->table(
                ['Toilet ID', 'Place ID', 'Place Name', 'Fields set from null to place data'],
                $sampleRows
            );
        }

        if ($dryRun) {
            $this->info('Dry-run complete. '.count($matching).' toilet(s) would be unflagged.');

            return self::SUCCESS;
        }

        $idsToUnflag = array_map(fn ($item) => $item['toilet']->id, $matching);

        if (! empty($idsToUnflag)) {
            foreach (array_chunk($idsToUnflag, $chunkSize) as $chunkIds) {
                Toilet::whereIn('id', $chunkIds)->update(['flagged' => false]);
            }

            $this->info('Successfully unflagged '.count($idsToUnflag).' toilet(s) whose last change only set fields from null to place data, website to null, or owner to place name.');
        } else {
            $this->info('No matching flagged toilets found to unflag.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the diff array representing the toilet's last revision or last update.
     */
    public function resolveDiff(Toilet $toilet): array
    {
        $diff = null;

        if ($toilet->latestRevision && ! empty($toilet->latestRevision->diff)) {
            $diff = $toilet->latestRevision->diff;
        }

        if (empty($diff) && ! empty($toilet->last_diff)) {
            $diff = $toilet->last_diff;
        }

        if (is_string($diff)) {
            $diff = json_decode($diff, true);
        }

        return is_array($diff) ? $diff : [];
    }

    /**
     * Check if the last change diff ONLY contains fields that were:
     * - set from null/unset to current place data values, OR
     * - had website set to null, OR
     * - had owner set to the current place name.
     *
     * @param  array<string, mixed>  $diff
     * @param  array<int, string>  $matchedFields  Populated with the list of matched field names
     */
    public function isLastChangeOnlySetFromNullToPlaceData(array $diff, ?Place $place, ?array &$matchedFields = []): bool
    {
        $matchedFields = [];

        if (empty($diff) || ! $place) {
            return false;
        }

        $substantiveChangesCount = 0;

        foreach ($diff as $key => $change) {
            $keyStr = (string) $key;

            if (in_array($keyStr, self::IGNORED_DIFF_KEYS, true)) {
                continue;
            }

            if (! is_array($change) || ! array_key_exists('new', $change)) {
                // If diff entry is malformed or not an old/new pair, do not unflag
                return false;
            }

            $oldVal = $change['old'] ?? null;
            $newVal = $change['new'] ?? null;

            // Ignore no-op change
            if ($oldVal === $newVal || (is_scalar($oldVal) && is_scalar($newVal) && (string) $oldVal === (string) $newVal)) {
                continue;
            }

            $substantiveChangesCount++;

            if (! $this->isSubstantiveChangeAllowed($keyStr, $oldVal, $newVal, $place)) {
                return false;
            }

            $matchedFields[] = $keyStr;
        }

        return $substantiveChangesCount > 0 && count($matchedFields) === $substantiveChangesCount;
    }

    /**
     * Check if a substantive field change is allowed:
     * 1. Owner set to current place name (allowed from null or non-null)
     * 2. Website set to null (allowed from null or non-null)
     * 3. Change of lat/lon to current place lat/lon if old lat/lon did not have a float value
     * 4. Any field set from null/unset to current place data value
     */
    public function isSubstantiveChangeAllowed(string $key, mixed $oldVal, mixed $newVal, Place $place): bool
    {
        // 1. Owner set to current place name (allowed from null or non-null)
        if ($key === 'owner' || $key === 'name' || $key === 'address') {
            if ($this->isFieldMatchingCurrentPlaceData($key, $newVal, $place)) {
                return true;
            }
        }

        // 2. Website set to null (allowed from null or non-null)
        if ($key === 'website') {
            return true;
        }

        // 3. Change of lat/lon to current place lat/lon if old lat/lon did not have a float value
        if ($key === 'lat' || $key === 'lon') {
            if ($this->isCoordinateNotAFloat($oldVal)) {
                return $this->isFieldMatchingCurrentPlaceData($key, $newVal, $place);
            }
        }

        // 4. Field set from null/unset to current place data value
        if ($this->isOldValueNullOrUnset($key, $oldVal)) {
            return $this->isFieldMatchingCurrentPlaceData($key, $newVal, $place);
        }

        return false;
    }

    /**
     * Check if a coordinate value represents an unset / non-float value (null, empty, non-numeric, 0, or integer without decimal places).
     */
    public function isCoordinateNotAFloat(mixed $val): bool
    {
        if ($val === null || $val === '' || ! is_numeric($val)) {
            return true;
        }

        $floatVal = (float) $val;

        // 0.0 is not a valid coordinate float
        if ($floatVal === 0.0) {
            return true;
        }

        // An integer / whole number with no fractional digits (e.g. 52, 52.0) is not a proper coordinate float value
        return round($floatVal, 0) == $floatVal;
    }

    /**
     * Check if an old value in diff represents null or unset state.
     */
    public function isOldValueNullOrUnset(string $key, mixed $oldVal): bool
    {
        $booleanFlags = [
            'is_unisex',
            'is_gender_separated',
            'has_wheelchair_access',
            'has_changing_table',
            'public_accessible',
            'temporary_closed',
        ];

        if (in_array($key, $booleanFlags, true)) {
            return $oldVal === null
                || $oldVal === ''
                || $oldVal === '0'
                || $oldVal === 0
                || $oldVal === false;
        }

        return $oldVal === null || $oldVal === '';
    }

    /**
     * Check if a field's new value matches the current place data.
     */
    public function isFieldMatchingCurrentPlaceData(string $key, mixed $newVal, Place $place): bool
    {
        $data = is_array($place->data) ? $place->data : [];

        switch ($key) {
            case 'owner':
            case 'name':
                if (! is_string($newVal) || trim($newVal) === '') {
                    return false;
                }
                $newTrimmed = trim($newVal);
                $placeName = $place->getName();
                $displayNameText = $data['displayName']['text'] ?? (is_string($data['displayName'] ?? null) ? $data['displayName'] : null);
                $rawName = $data['name'] ?? null;

                $validNames = array_filter(
                    [$placeName, $displayNameText, $rawName],
                    fn ($n) => is_string($n) && ! str_starts_with($n, 'places/') && trim($n) !== ''
                );

                foreach ($validNames as $validName) {
                    if (trim((string) $validName) === $newTrimmed) {
                        return true;
                    }
                }

                return false;

            case 'address':
                if (! is_string($newVal) || trim($newVal) === '') {
                    return false;
                }
                $addr = $data['formattedAddress'] ?? $data['formatted_address'] ?? $data['address'] ?? null;

                return $addr !== null && trim((string) $addr) === trim($newVal);

            case 'website':
                if (! is_string($newVal) || trim($newVal) === '') {
                    return false;
                }
                $web = $data['websiteUri'] ?? $data['website'] ?? null;
                if ($web === null) {
                    return false;
                }

                return trim($newVal) === trim((string) $web)
                    || rtrim(trim($newVal), '/') === rtrim(trim((string) $web), '/');

            case 'lat':
                if (! is_numeric($newVal)) {
                    return false;
                }
                $lat = $place->getLat();

                return $lat !== null && abs((float) $newVal - (float) $lat) < 0.0001;

            case 'lon':
                if (! is_numeric($newVal)) {
                    return false;
                }
                $lon = $place->getLon();

                return $lon !== null && abs((float) $newVal - (float) $lon) < 0.0001;

            case 'place_id':
                return (string) $newVal === (string) $place->place_id;

            case 'place_opening_hours':
                $rawOpening = $data['regularOpeningHours'] ?? $data['opening_hours'] ?? $data['openingHours'] ?? null;
                $placePeriods = null;
                if (is_array($rawOpening) && isset($rawOpening['periods']) && is_array($rawOpening['periods'])) {
                    $placePeriods = PlaceToiletService::normalizePeriodPoints($rawOpening['periods']);
                }
                if ($placePeriods === null) {
                    return false;
                }
                $newPeriods = is_string($newVal) ? json_decode($newVal, true) : $newVal;
                if (! is_array($newPeriods)) {
                    return false;
                }

                return json_encode($newPeriods) === json_encode($placePeriods);

            case 'public_accessible':
                if ($newVal !== '1' && $newVal !== 1 && $newVal !== true) {
                    return false;
                }
                $types = $data['types'] ?? [];

                return PlaceToiletService::isPublicAccessibleType(is_array($types) ? $types : []);

            case 'temporary_closed':
                if ($newVal !== '1' && $newVal !== 1 && $newVal !== true) {
                    return false;
                }
                $status = $data['businessStatus'] ?? $data['business_status'] ?? null;

                return in_array($status, ['CLOSED_TEMPORARILY', 'CLOSED_PERMANENTLY'], true);

            case 'has_wheelchair_access':
                if ($newVal !== '1' && $newVal !== 1 && $newVal !== true) {
                    return false;
                }
                $hasRestroomWheelchair = ! empty($data['accessibilityOptions']['wheelchairAccessibleRestroom'])
                    || ! empty($data['accessibility_options']['wheelchair_accessible_restroom'])
                    || ! empty($data['wheelchairAccessibleRestroom']);
                $isPb = in_array('public_bathroom', (array) ($data['types'] ?? []), true);
                $hasEntranceWheelchair = $isPb && (
                    ! empty($data['accessibilityOptions']['wheelchairAccessibleEntrance'])
                    || ! empty($data['accessibility_options']['wheelchair_accessible_entrance'])
                    || ! empty($data['wheelchairAccessibleEntrance'])
                );

                return $hasRestroomWheelchair || $hasEntranceWheelchair;

            case 'is_unisex':
                if ($newVal !== '1' && $newVal !== 1 && $newVal !== true) {
                    return false;
                }
                $hasRestroom = ! empty($data['restroom'])
                    || ! empty($data['hasRestroom'])
                    || ! empty($data['accessibilityOptions']['wheelchairAccessibleRestroom'])
                    || ! empty($data['accessibility_options']['wheelchair_accessible_restroom'])
                    || ! empty($data['wheelchairAccessibleRestroom']);
                $isPb = in_array('public_bathroom', (array) ($data['types'] ?? []), true);

                return $hasRestroom || $isPb;

            case 'status':
                if ($newVal !== 'active') {
                    return false;
                }
                $hasRestroom = ! empty($data['restroom']) || ! empty($data['hasRestroom']);
                $isPb = in_array('public_bathroom', (array) ($data['types'] ?? []), true);

                return $hasRestroom || $isPb;

            default:
                return false;
        }
    }
}
