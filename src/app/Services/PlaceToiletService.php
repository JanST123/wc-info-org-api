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
    public function __construct(
        private GooglePlacesService $placesService,
    ) {}

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

        $existingToilet = Toilet::where('place_id', $placeId)->first();

        if ($existingToilet) {
            return $existingToilet;
        }

        $details = $this->resolveDetails($placeData, $fetchedDetails);

        if (empty($details['website'])) {
            Log::info('Place has no website, creating hidden toilet', [
                'place_id' => $placeId,
                'place_name' => $details['name'] ?? null,
            ]);

            return $this->createHiddenToilet($placeId, $details['name'] ?? null, $details['location'] ?? null);
        }

        // Log::debug('Place has a website');

        $crawlResult = $this->crawlWithLogging($placeId, $details['website']);

        if ($crawlResult === null) {
            // Log::debug('Crawl returned without result');
            return $this->createHiddenToilet($placeId, $details['name'] ?? null, $details['location'] ?? null);
        }

        // Log::debug('Crawl had a result: ' . print_r($crawlResult, 1));

        $toiletType = $crawlResult['toiletType'] ?? 'none';
        $contactEmail = $crawlResult['contactEmail'] ?? '';
        $status = $toiletType === 'none' ? 'hidden' : 'active';

        Log::info('Crawl result parsed', [
            'place_id' => $placeId,
            'website' => $details['website'],
            'toilet_type' => $toiletType,
            'status' => $status,
            'contact_email' => $contactEmail,
            'result_count' => $crawlResult['resultCount'] ?? null,
        ]);

        $toilet = Toilet::create([
            'name' => 'Toilette',
            'owner' => $details['name'] ?? null,
            'lat' => $details['location']['lat'] ?? null,
            'lon' => $details['location']['lng'] ?? null,
            'place_id' => $placeId,
            'contact_email' => $contactEmail,
            'status' => $status,
            'source' => 'auto_crawl',
        ]);

        if ($toiletType !== 'none') {
            $toilet->update(['name' => 'WC #'.$toilet->id]);

            $this->applyTypeFlags($toilet->id, $toiletType);
            $this->insertProperty($toilet->id, 'website', $details['website']);

            if (is_array($details['openingHours']) && isset($details['openingHours']['periods'])) {
                $this->insertProperty($toilet->id, 'place_opening_hours', json_encode($details['openingHours']['periods']));
            }

            if (! empty($details['formattedAddress'])) {
                $this->insertProperty($toilet->id, 'address', $details['formattedAddress']);
            }

            $placeTypes = $this->checkAndUpdatePlaceType($placeId, $details['types'] ?? null);

            if (self::isPublicAccessibleType($placeTypes)) {
                $this->insertProperty($toilet->id, 'public_accessible', '1');
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

        // Always update coordinates from Google unless the user moved the toilet manually.
        if (! empty($details['location'])) {
            if (! $toilet->isUserOverridden('lat') && (float) $toilet->lat != (float) ($details['location']['lat'] ?? null)) {
                $changes['lat'] = ['old' => $toilet->lat, 'new' => $details['location']['lat']];
                $toilet->lat = $details['location']['lat'];
                $updated = true;
            }

            if (! $toilet->isUserOverridden('lon') && (float) $toilet->lon != (float) ($details['location']['lng'] ?? null)) {
                $changes['lon'] = ['old' => $toilet->lon, 'new' => $details['location']['lng']];
                $toilet->lon = $details['location']['lng'];
                $updated = true;
            }
        }

        // Update owner from place name unless the user edited it.
        if (! empty($details['name']) && ! $toilet->isUserOverridden('owner') && $toilet->owner !== $details['name']) {
            $changes['owner'] = ['old' => $toilet->owner, 'new' => $details['name']];
            $toilet->owner = $details['name'];
            $updated = true;
        }

        $crawlResult = null;

        if (! empty($details['website'])) {
            $crawlResult = $this->crawlWithLogging($placeId, $details['website']);

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
            Log::info('Place has no website during update', [
                'toilet_id' => $toilet->id,
                'place_id' => $placeId,
            ]);
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
        

        return $updated || $crawlResult !== null || count($changes) > 0;
    }

    private function resolveDetails(array $placeData, ?array $fetchedDetails): array
    {
        $extract = function (array $data): array {
            $placeId = $data['id'] ?? $data['place_id'] ?? null;
            $name = $data['displayName']['text'] ?? (is_string($data['displayName'] ?? null) ? $data['displayName'] : null) ?? $data['name'] ?? null;
            $website = $data['websiteUri'] ?? $data['website'] ?? null;
            $address = $data['formattedAddress'] ?? $data['formatted_address'] ?? null;

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

            return [
                'place_id' => $placeId,
                'name' => $name,
                'website' => $website,
                'location' => $location,
                'openingHours' => $openingHours,
                'formattedAddress' => $address,
                'types' => $types,
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
                }
            }
        }

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
        ]);
    }

    private function applyTypeFlags(int $toiletId, string $toiletType): void
    {
        $flags = [
            'is_unisex' => $toiletType === 'forall' || str_contains($toiletType, 'u'),
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
            'is_unisex' => $toiletType === 'forall' || str_contains($toiletType, 'u'),
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

            $details = $this->placesService->fetchPlaceDetails($placeId);
            $types = $details['types'] ?? [];
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
