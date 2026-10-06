<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Toilet;
use App\Models\Type;
use App\Models\TypeXPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlaceToiletService
{
    private ToiletRevisionService $revisionService;

    public function __construct(
        private GooglePlacesService $placesService,
        ?ToiletRevisionService $revisionService = null,
    ) {
        $this->revisionService = $revisionService ?? app(ToiletRevisionService::class);
    }

    /**
     * Create or update a toilet for the given place data.
     *
     * If no toilet exists for the place, the website is crawled and a new
     * toilet is created. The old crawl result type string is converted to
     * the new boolean property flags.
     *
     * @return Toilet|null The created/updated toilet, or null if no toilet should be created.
     */
    public function createToiletFromPlace(array $placeData, ?array $fetchedDetails = null): ?Toilet
    {
        $placeId = $placeData['id'] ?? $placeData['place_id'] ?? null;

        if (! $placeId) {
            return null;
        }

        $existingToilet = Toilet::where('place_id', $placeId)
            ->where('status', '!=', 'deleted')
            ->first();

        // if ($existingToilet) {
        //     return $existingToilet;
        // }
        if ($existingToilet) {
            $this->updateToiletFromPlace($existingToilet, $placeData, $fetchedDetails);

            return $existingToilet;
        }
        $details = $this->resolveDetails($placeData, $fetchedDetails);

        // if the place type is 'public_bathroom' we force adding this toilet as active and public_accessible, regardless of the crawl result
        $isPublicBathroom = is_array($details['types'] ?? null) && in_array('public_bathroom', $details['types'], true);
        if ($isPublicBathroom) {
            Log::info('Place is of public_bathroom type, force creating an active, public_accessible toilet', [
                'place_id' => $placeId,
                'place_name' => $details['name'] ?? null,
            ]);
        }

        $hasRestroom = ($details['hasRestroom'] ?? false) || ! empty($details['wheelchairAccessibleRestroom']);
        if ($hasRestroom) {
            Log::info('Place has a restroom, force creating an active toilet', [
                'place_id' => $placeId,
                'place_name' => $details['name'] ?? null,
            ]);
        }

        if (! $hasRestroom && ! $isPublicBathroom && empty($details['website'])) {
            // no way to get any toilet information
            Log::info('Place has no website, creating hidden toilet', [
                'place_id' => $placeId,
                'place_name' => $details['name'] ?? null,
            ]);

            return $this->createHiddenToilet($placeId, $details['name'] ?? null, $details['location'] ?? null);
        }

        $crawlResult = empty($details['website']) ? null : $this->crawlWithLogging($placeId, $details['website']);

        if (! $hasRestroom && ! $isPublicBathroom && $crawlResult === null) {
            return $this->createHiddenToilet($placeId, $details['name'] ?? null, $details['location'] ?? null);
        }

        /*
         * how to determine the toilet type:
         * - if the place has a restroom it's at least "forall" (unisex)
         * - if $isPublicBathroom is true, we can also say it's at least "forall" (unisex)
         * - if we have a wheelchairAccessibleRestroom, this implies it has a restroom -> so we have "forall" (unisex) and "d" (wheelchair accessible)
         * - if it's a public bathroom and we have wheelchairAccessibleEntrance, this implies it has a restroom -> so we have "forall" (unisex) and "d" (wheelchair accessible)
         * - otherwise the crawl result (which is more magic than knowledge) is used to determine the toilet type, which can be "none", "forall", "foralld", "mw", "mwd", "u", "ud", "b", "bd"
         */

        $toiletType = 'none';
        if ($hasRestroom) {
            $toiletType = 'forall';
            if (! empty($details['wheelchairAccessibleRestroom'])) {
                $toiletType .= 'd';
            }
        }

        if ($isPublicBathroom) {
            // if it's a public bathroom we can at least say it IS a toilet, so not none
            if ($toiletType === 'none') {
                $toiletType = 'forall';
            }
            if ((! empty($details['wheelchairAccessibleEntrance']) || ! empty($details['wheelchairAccessibleRestroom'])) && ! str_contains($toiletType, 'd')) {
                $toiletType .= 'd';
            }
        }
        if ($toiletType === 'none' && $crawlResult !== null) {
            $toiletType = $crawlResult['toiletType'] ?? 'none';
        }

        $status = $toiletType === 'none' ? 'hidden' : 'active';

        $contactEmail = $crawlResult['contactEmail'] ?? '';

        if ($crawlResult !== null) {
            Log::info('Crawl result parsed', [
                'place_id' => $placeId,
                'website' => $details['website'],
                'toilet_type' => $toiletType,
                'status' => $status,
                'contact_email' => $contactEmail,
                'result_count' => $crawlResult['resultCount'] ?? null,
            ]);
        }

        $source = 'auto_crawl';
        if ($isPublicBathroom) {
            $source = 'auto_crawl_with_public_bathroom';
        } elseif ($hasRestroom) {
            $source = 'auto_crawl_with_restroom';
        }

        $toilet = Toilet::create([
            'name' => 'Toilette',
            'owner' => $details['name'] ?? null,
            'lat' => $details['location']['lat'] ?? null,
            'lon' => $details['location']['lng'] ?? null,
            'place_id' => $placeId,
            'contact_email' => $contactEmail,
            'status' => $status,
            'source' => $source,
            'last_places_fetch' => now(),
            'last_crawled' => now(),
            'flagged' => true, // we flag the toilet for review if any of the main fields changed, so that a human can check if the crawl result is correct.
        ]);

        if ($toiletType !== 'none') {
            $toilet->update(['name' => 'WC #'.$toilet->id]);

            $this->applyTypeFlags($toilet->id, $toiletType);
            if (! empty($details['website'])) {
                $this->insertProperty($toilet->id, 'website', $details['website']);
            }

            if (is_array($details['openingHours']) && isset($details['openingHours']['periods'])) {
                $this->insertProperty($toilet->id, 'place_opening_hours', json_encode($details['openingHours']['periods']));
            }

            if (! empty($details['formattedAddress'])) {
                $this->insertProperty($toilet->id, 'address', $details['formattedAddress']);
            }

            $placeTypes = $this->checkAndUpdatePlaceType($placeId, $details['types'] ?? null);

            if ($isPublicBathroom || self::isPublicAccessibleType($placeTypes)) {
                $this->insertProperty($toilet->id, 'public_accessible', '1');
            }

            if (in_array($details['businessStatus'] ?? null, ['CLOSED_TEMPORARILY', 'CLOSED_PERMANENTLY'], true)) {
                $this->insertProperty($toilet->id, 'temporary_closed', '1');
            }
        }

        return $toilet;
    }

    /**
     * Update an existing toilet from Google Place details, respecting user overrides.
     *
     * The website is crawled and toilet attributes/properties are updated, but
     * any field or property the user has manually set is left untouched.
     */
    public function updateToiletFromPlace(Toilet $toilet, array $placeData, ?array $fetchedDetails = null, array &$changes = []): bool
    {
        $placeId = $placeData['place_id'] ?? $toilet->place_id;

        if (empty($placeId)) {
            return false;
        }

        $details = $this->resolveDetails($placeData, $fetchedDetails);
        $updated = false;

        $toilet->last_places_fetch = now();

        // Always update coordinates from Google unless the user moved the toilet manually.
        if (! empty($details['location'])) {
            $newLat = isset($details['location']['lat']) && $details['location']['lat'] !== null ? round((float) $details['location']['lat'], 7) : null;
            $newLon = isset($details['location']['lng']) && $details['location']['lng'] !== null ? round((float) $details['location']['lng'], 7) : null;

            if (! $toilet->isUserOverridden('lat') && $newLat !== null && ! Toilet::areCoordinatesEqual($toilet->lat, $newLat)) {
                $changes['lat'] = ['old' => $toilet->lat, 'new' => $newLat];
                $toilet->lat = $newLat;
                $updated = true;
            }

            if (! $toilet->isUserOverridden('lon') && $newLon !== null && ! Toilet::areCoordinatesEqual($toilet->lon, $newLon)) {
                $changes['lon'] = ['old' => $toilet->lon, 'new' => $newLon];
                $toilet->lon = $newLon;
                $updated = true;
            }
        }

        // Update owner from place name unless the user edited it.
        if (! empty($details['name']) && ! $toilet->isUserOverridden('owner') && $toilet->owner !== $details['name']) {
            $changes['owner'] = ['old' => $toilet->owner, 'new' => $details['name']];
            $toilet->owner = $details['name'];
            $updated = true;
        }

        $hasRestroom = ($details['hasRestroom'] ?? false) || ! empty($details['wheelchairAccessibleRestroom']);
        $isPublicBathroom = is_array($details['types'] ?? null) && in_array('public_bathroom', $details['types'], true);

        $placeToiletType = 'none';
        if ($hasRestroom || $isPublicBathroom) {
            $baseGenderType = $toilet->isFlagSet('is_gender_separated') ? 'mw' : 'forall';
            $placeToiletType = $baseGenderType;

            if ($toilet->isFlagSet('has_changing_table')) {
                $placeToiletType .= 'b';
            }

            $hasWheelchair = $toilet->isFlagSet('has_wheelchair_access')
                || ! empty($details['wheelchairAccessibleRestroom'])
                || ($isPublicBathroom && ! empty($details['wheelchairAccessibleEntrance']));

            if ($hasWheelchair) {
                $placeToiletType .= 'd';
            }
        }

        if ($placeToiletType !== 'none') {
            $newStatus = 'active';
            if (! $toilet->isUserOverridden('status') && $toilet->status !== $newStatus) {
                $changes['status'] = ['old' => $toilet->status, 'new' => $newStatus];
                $toilet->status = $newStatus;
                $updated = true;

                if (! $toilet->isUserOverridden('name') && $toilet->name === 'Toilette') {
                    $newName = 'WC #'.$toilet->id;
                    $changes['name'] = ['old' => $toilet->name, 'new' => $newName];
                    $toilet->name = $newName;
                }
            }

            if ($toilet->isDirty()) {
                $toilet->save();
                $updated = true;
            }

            $this->applyTypeFlagsWithOverrideCheck($toilet->id, $placeToiletType, $changes);
        }

        $crawlResult = null;
        $needsCrawl = empty($toilet->last_crawled) || $toilet->last_crawled->lte(now()->subMonths(3));

        // also toilets do not need a crawl if they have toilet type flags set, as those are derived from the crawl result or place metadata.
        if ($placeToiletType !== 'none' || $toilet->isFlagSet('is_unisex') || $toilet->isFlagSet('is_gender_separated') || $toilet->isFlagSet('has_wheelchair_access') || $toilet->isFlagSet('has_changing_table')) {
            $needsCrawl = false;
        }

        if (! empty($details['website'])) {
            if ($needsCrawl) {
                $crawlResult = $this->crawlWithLogging($placeId, $details['website']);
                $toilet->last_crawled = now();

                $toiletType = $crawlResult['toiletType'] ?? 'none';
                $newStatus = $toiletType === 'none' ? 'hidden' : 'active';

                // Only change status if the user has not manually set it.
                if (! $toilet->isUserOverridden('status') && $toilet->status !== $newStatus) {
                    $changes['status'] = ['old' => $toilet->status, 'new' => $newStatus];
                    $toilet->status = $newStatus;
                    $updated = true;

                    // When the cron (re-)activates a toilet, give it the default active name.
                    if ($newStatus === 'active' && ! $toilet->isUserOverridden('name') && $toilet->name === 'Toilette') {
                        $newName = 'WC #'.$toilet->id;
                        $changes['name'] = ['old' => $toilet->name, 'new' => $newName];
                        $toilet->name = $newName;
                    }
                }

                if ($toilet->isDirty()) {
                    $toilet->save();
                    $updated = true;
                }

                // Update boolean flags unless the user set them manually.
                $this->applyTypeFlagsWithOverrideCheck($toilet->id, $toiletType, $changes);
            } else {
                $reason = (! empty($toilet->last_crawled) && $toilet->last_crawled->gt(now()->subMonths(3)))
                    ? 'crawled recently (<3 months)'
                    : 'toilet type flags already set';

                Log::info("Skipping website crawl for toilet: {$reason}", [
                    'toilet_id' => $toilet->id,
                    'place_id' => $placeId,
                    'reason' => $reason,
                    'last_crawled' => $toilet->last_crawled?->toIso8601String(),
                ]);
            }
        } else {
            Log::info('Place has no website during update', [
                'toilet_id' => $toilet->id,
                'place_id' => $placeId,
            ]);
        }

        if ($toilet->isDirty(['name', 'owner', 'lat', 'lon', 'status'])) {
            if (! $toilet->flagged) {
                $changes['flagged'] = ['old' => false, 'new' => true];
            }
            $toilet->flagged = true; // we flag the toilet for review if any of the main fields changed, so that a human can check if the crawl result is correct.
        }

        if ($toilet->isDirty()) {
            $toilet->save();
        }

        // Update website/address/opening_hours properties unless the user set them manually.
        $this->setPropertyWithOverrideCheck($toilet->id, 'website', $details['website'] ?? null, $changes);
        $this->setPropertyWithOverrideCheck($toilet->id, 'address', $details['formattedAddress'] ?? null, $changes);

        if (is_array($details['openingHours']) && isset($details['openingHours']['periods'])) {
            $this->setPropertyWithOverrideCheck($toilet->id, 'place_opening_hours', json_encode($details['openingHours']['periods']), $changes);
        } else {
            $this->setPropertyWithOverrideCheck($toilet->id, 'place_opening_hours', null, $changes);
        }

        $placeTypes = $this->checkAndUpdatePlaceType($placeId, $details['types'] ?? null);

        if (! $toilet->isUserOverridden('public_accessible') && self::isPublicAccessibleType($placeTypes)) {
            $this->setPropertyWithOverrideCheck($toilet->id, 'public_accessible', '1', $changes);
        }

        if (isset($details['businessStatus'])) {
            $isClosed = in_array($details['businessStatus'], ['CLOSED_TEMPORARILY', 'CLOSED_PERMANENTLY'], true);
            $this->setPropertyWithOverrideCheck($toilet->id, 'temporary_closed', $isClosed ? '1' : null, $changes);
        }

        if (! empty($changes)) {
            if (! $toilet->flagged) {
                $changes['flagged'] = ['old' => false, 'new' => true];
                $toilet->flagged = true;
            }
            $toilet->last_diff = json_encode($changes);
            $toilet->save();

            $this->revisionService->recordRevision(
                $toilet,
                'update-from-place',
                $changes,
                "Updated from place ({$placeId})"
            );
        }

        return $updated || $crawlResult !== null || count($changes) > 0;
    }

    private function resolveDetails(array $placeData, ?array $fetchedDetails): array
    {
        $extract = function (array $data): array {
            $placeId = $data['id'] ?? $data['place_id'] ?? null;
            $name = $data['displayName']['text'] ?? (is_string($data['displayName'] ?? null) ? $data['displayName'] : null) ?? $data['name'] ?? null;
            $website = $data['websiteUri'] ?? $data['website'] ?? null;
            $address = $data['formattedAddress'] ?? $data['formatted_address'] ?? null;
            $businessStatus = $data['business_status'] ?? $data['businessStatus'] ?? null;
            $hasRestroom = isset($data['restroom'])
                ? (bool) $data['restroom']
                : (isset($data['hasRestroom']) ? (bool) $data['hasRestroom'] : null);

            $lat = $data['location']['latitude'] ?? $data['location']['lat'] ?? $data['geometry']['location']['lat'] ?? null;
            $lng = $data['location']['longitude'] ?? $data['location']['lng'] ?? $data['geometry']['location']['lng'] ?? null;
            $location = ($lat !== null && $lng !== null) ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;

            $rawOpening = $data['regularOpeningHours'] ?? $data['opening_hours'] ?? $data['openingHours'] ?? null;
            $openingHours = null;
            if (is_array($rawOpening) && isset($rawOpening['periods']) && is_array($rawOpening['periods'])) {
                $openingHours = ['periods' => self::normalizePeriodPoints($rawOpening['periods'])];
            }

            $types = $data['types'] ?? null;
            if (! is_array($types)) {
                $types = null;
            }

            $wheelchairAccessibleEntrance = isset($data['accessibilityOptions']['wheelchairAccessibleEntrance'])
                ? (bool) $data['accessibilityOptions']['wheelchairAccessibleEntrance']
                : (isset($data['accessibility_options']['wheelchair_accessible_entrance'])
                    ? (bool) $data['accessibility_options']['wheelchair_accessible_entrance']
                    : (isset($data['wheelchairAccessibleEntrance']) ? (bool) $data['wheelchairAccessibleEntrance'] : null));

            $wheelchairAccessibleRestroom = isset($data['accessibilityOptions']['wheelchairAccessibleRestroom'])
                ? (bool) $data['accessibilityOptions']['wheelchairAccessibleRestroom']
                : (isset($data['accessibility_options']['wheelchair_accessible_restroom'])
                    ? (bool) $data['accessibility_options']['wheelchair_accessible_restroom']
                    : (isset($data['wheelchairAccessibleRestroom']) ? (bool) $data['wheelchairAccessibleRestroom'] : null));

            return [
                'place_id' => $placeId,
                'name' => $name,
                'website' => $website,
                'location' => $location,
                'openingHours' => $openingHours,
                'formattedAddress' => $address,
                'types' => $types,
                'businessStatus' => $businessStatus,
                'wheelchairAccessibleEntrance' => $wheelchairAccessibleEntrance,
                'wheelchairAccessibleRestroom' => $wheelchairAccessibleRestroom,
                'hasRestroom' => $hasRestroom,
            ];
        };

        $details = $extract($placeData);

        if ($fetchedDetails !== null) {
            $fetched = $extract($fetchedDetails);
            $details['name'] = $fetched['name'] ?? $details['name'];
            $details['website'] = $fetched['website'] ?? $details['website'];
            $details['location'] = $fetched['location'] ?? $details['location'];
            $details['openingHours'] = $fetched['openingHours'] ?? $details['openingHours'];
            $details['formattedAddress'] = $fetched['formattedAddress'] ?? $details['formattedAddress'];
            $details['types'] = $fetched['types'] ?? $details['types'];
            $details['businessStatus'] = $fetched['businessStatus'] ?? $details['businessStatus'];
            $details['wheelchairAccessibleEntrance'] = $fetched['wheelchairAccessibleEntrance'] ?? $details['wheelchairAccessibleEntrance'];
            $details['wheelchairAccessibleRestroom'] = $fetched['wheelchairAccessibleRestroom'] ?? $details['wheelchairAccessibleRestroom'];
            $details['hasRestroom'] = $fetched['hasRestroom'] ?? $details['hasRestroom'];
        } elseif (empty($details['website']) || empty($details['name']) || empty($details['location'])) {
            $placeId = $details['place_id'] ?? $placeData['place_id'] ?? $placeData['id'] ?? null;
            if ($placeId) {
                $fetchedRaw = $this->placesService->fetchPlaceDetails($placeId);
                if ($fetchedRaw) {
                    $fetched = $extract($fetchedRaw);
                    $details['name'] = $fetched['name'] ?? $details['name'];
                    $details['website'] = $fetched['website'] ?? $details['website'];
                    $details['location'] = $fetched['location'] ?? $details['location'];
                    $details['openingHours'] = $fetched['openingHours'] ?? $details['openingHours'];
                    $details['formattedAddress'] = $fetched['formattedAddress'] ?? $details['formattedAddress'];
                    $details['types'] = $fetched['types'] ?? $details['types'];
                    $details['businessStatus'] = $fetched['businessStatus'] ?? $details['businessStatus'];
                    $details['wheelchairAccessibleEntrance'] = $fetched['wheelchairAccessibleEntrance'] ?? $details['wheelchairAccessibleEntrance'];
                    $details['wheelchairAccessibleRestroom'] = $fetched['wheelchairAccessibleRestroom'] ?? $details['wheelchairAccessibleRestroom'];
                    $details['hasRestroom'] = $fetched['hasRestroom'] ?? $details['hasRestroom'];
                }
            }
        }

        $details['wheelchairAccessibleEntrance'] = (bool) ($details['wheelchairAccessibleEntrance'] ?? false);
        $details['wheelchairAccessibleRestroom'] = (bool) ($details['wheelchairAccessibleRestroom'] ?? false);
        $details['hasRestroom'] = (bool) ($details['hasRestroom'] ?? false);

        return $details;
    }

    /**
     * Normalize period points to {"day": int, "hour": int, "minute": int} format.
     */
    public static function normalizePeriodPoints(array $periods): array
    {
        $normalized = [];
        foreach ($periods as $period) {
            if (! is_array($period) || ! isset($period['open']) || ! is_array($period['open']) || ! isset($period['open']['day'])) {
                continue;
            }

            $open = self::normalizePoint($period['open']);
            if ($open === null) {
                continue;
            }

            $entry = ['open' => $open];

            if (isset($period['close']) && is_array($period['close'])) {
                $close = self::normalizePoint($period['close']);
                if ($close !== null) {
                    $entry['close'] = $close;
                }
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    private static function normalizePoint(array $point): ?array
    {
        $day = (int) ($point['day'] ?? 0);
        $hour = null;
        $minute = 0;

        if (isset($point['hour'])) {
            $hour = (int) $point['hour'];
            $minute = (int) ($point['minute'] ?? 0);
        } elseif (isset($point['hours'])) {
            $hour = (int) $point['hours'];
            $minute = (int) ($point['minutes'] ?? 0);
        } elseif (isset($point['time']) && is_string($point['time']) && strlen($point['time']) >= 4) {
            $hour = (int) substr($point['time'], 0, 2);
            $minute = (int) substr($point['time'], 2, 2);
        }

        if ($hour === null) {
            return null;
        }

        return [
            'day' => $day,
            'hour' => $hour,
            'minute' => $minute,
        ];
    }

    private function crawlWithLogging(string $placeId, string $website): ?array
    {
        Log::info('Crawling website for place', [
            'place_id' => $placeId,
            'website' => $website,
        ]);

        try {
            $crawlResult = $this->placesService->crawlWebsite($website, 'wc OR toilet OR toilette OR klo OR restroom OR impressum OR kontakt');
        } catch (\Throwable $e) {
            Log::warning('Place crawl failed', [
                'place_id' => $placeId,
                'website' => $website,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        Log::info('Crawl result parsed', [
            'place_id' => $placeId,
            'website' => $website,
            'toilet_type' => $crawlResult['toiletType'] ?? 'none',
            'contact_email' => $crawlResult['contactEmail'] ?? '',
            'result_count' => $crawlResult['resultCount'] ?? null,
        ]);

        return $crawlResult;
    }

    private function createHiddenToilet(string $placeId, ?string $placeName, ?array $placeLocation): ?Toilet
    {
        if (empty($placeLocation)) {
            return null;
        }

        return Toilet::create([
            'name' => $placeName ?: 'Toilette',
            'owner' => $placeName ?: '',
            'lat' => $placeLocation['lat'] ?? null,
            'lon' => $placeLocation['lng'] ?? null,
            'place_id' => $placeId,
            'status' => 'hidden',
            'source' => 'auto_crawl',
            'flagged' => false, // this toilets are only created hidden so they are not created on every discovery run, so we do not flag for review
        ]);
    }

    private function applyTypeFlags(int $toiletId, string $toiletType): void
    {
        $flags = [
            'is_unisex' => str_starts_with($toiletType, 'forall') || str_contains($toiletType, 'u'),
            'is_gender_separated' => str_contains($toiletType, 'mw'),
            'has_wheelchair_access' => str_contains($toiletType, 'd'),
            'has_changing_table' => str_contains($toiletType, 'b'),
        ];

        foreach ($flags as $flag => $value) {
            if ($value) {
                DB::table('toilet_properties')->updateOrInsert(
                    ['fk_toiletId' => $toiletId, 'type' => $flag],
                    ['value' => '1']
                );
            }
        }
    }

    private function applyTypeFlagsWithOverrideCheck(int $toiletId, string $toiletType, array &$changes = []): void
    {
        $flags = [
            'is_unisex' => str_starts_with($toiletType, 'forall') || str_contains($toiletType, 'u'),
            'is_gender_separated' => str_contains($toiletType, 'mw'),
            'has_wheelchair_access' => str_contains($toiletType, 'd'),
            'has_changing_table' => str_contains($toiletType, 'b'),
        ];

        foreach ($flags as $flag => $value) {
            $existing = DB::table('toilet_properties')
                ->where('fk_toiletId', $toiletId)
                ->where('type', $flag)
                ->first();

            if ($existing && $existing->user_overridden) {
                continue;
            }

            if ($value) {
                if (! $existing || $existing->value !== '1') {
                    $changes[$flag] = ['old' => $existing?->value ?: '0', 'new' => '1'];
                }
                DB::table('toilet_properties')->updateOrInsert(
                    ['fk_toiletId' => $toiletId, 'type' => $flag],
                    ['value' => '1']
                );
            } elseif ($existing) {
                $changes[$flag] = ['old' => $existing->value, 'new' => '0'];
                DB::table('toilet_properties')
                    ->where('fk_toiletId', $toiletId)
                    ->where('type', $flag)
                    ->delete();
            }
        }
    }

    private function setPropertyWithOverrideCheck(int $toiletId, string $type, ?string $value, array &$changes = []): void
    {
        $existing = DB::table('toilet_properties')
            ->where('fk_toiletId', $toiletId)
            ->where('type', $type)
            ->first();

        if ($existing && $existing->user_overridden) {
            return;
        }

        if ($value === null || $value === '') {
            if ($existing) {
                $changes[$type] = ['old' => $existing->value, 'new' => null];
                DB::table('toilet_properties')
                    ->where('fk_toiletId', $toiletId)
                    ->where('type', $type)
                    ->delete();
            }

            return;
        }

        if (! $existing || $existing->value !== $value) {
            $changes[$type] = ['old' => $existing?->value, 'new' => $value];
        }

        DB::table('toilet_properties')->updateOrInsert(
            ['fk_toiletId' => $toiletId, 'type' => $type],
            ['value' => $value]
        );
    }

    private function insertProperty(int $toiletId, string $type, string $value): void
    {
        DB::table('toilet_properties')->insert([
            'fk_toiletId' => $toiletId,
            'type' => $type,
            'value' => $value,
        ]);
    }

    /**
     * @param  array<int, string>  $types
     */
    public static function isPublicAccessibleType(array $types): bool
    {
        $publicTypes = (array) config('wcinfo.public_accessible_types', []);

        return count(array_intersect($types, $publicTypes)) > 0;
    }

    /**
     * @param  array<int, string>|null  $types
     * @return array<int, string>
     */
    private function checkAndUpdatePlaceType(string $placeId, ?array $types = null): array
    {
        if (empty($types)) {
            $existing = DB::table('type_x_place')
                ->join('types', 'type_x_place.type_id', '=', 'types.id')
                ->where('type_x_place.place_id', $placeId)
                ->pluck('types.type')
                ->all();

            if (! empty($existing)) {
                return $existing;
            }

            $cachedPlace = Place::where('place_id', $placeId)->first();
            if ($cachedPlace && is_array($cachedPlace->data)) {
                $types = $cachedPlace->data['types'] ?? [];
            } else {
                $details = $this->placesService->fetchPlaceDetails($placeId);
                $types = $details['types'] ?? [];
            }
        }

        if (empty($types) || ! is_array($types)) {
            return [];
        }

        foreach ($types as $typeName) {
            $type = Type::firstOrCreate(['type' => $typeName]);

            TypeXPlace::insertOrIgnore([
                'type_id' => $type->id,
                'place_id' => $placeId,
            ]);
        }

        return $types;
    }
}
