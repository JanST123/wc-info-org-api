<?php

declare(strict_types=1);
namespace App\Console\Commands;

ini_alter('memory_limit', '512M');

use App\Models\GoogleNearbySearchCache;
use App\Models\Place;
use App\Models\Toilet;
use App\Services\PlaceToiletService;
use App\Services\ToiletRevisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtractNearbyCachePlacesCommand extends Command
{
    protected $signature = 'app:extract-nearby-cache-places
                            {--dry-run : Preview changes without writing to database}
                            {--force : Force update existing places even if no new missing fields are added}
                            {--update-toilets : Also sync missing opening hours and website properties to associated toilets}
                            {--chunk-size=200 : Number of cache rows to process per chunk}';

    protected $description = 'Extract places from google_nearby_search_cache table and add or update them in the places table';

    private bool $dryRun = false;

    private bool $force = false;

    private bool $updateToilets = false;

    private ToiletRevisionService $revisionService;

    public function handle(ToiletRevisionService $revisionService): int
    {
        $this->revisionService = $revisionService;
        $this->dryRun = (bool) $this->option('dry-run');
        $this->force = (bool) $this->option('force');
        $this->updateToilets = (bool) $this->option('update-toilets');
        $chunkSize = max(10, (int) $this->option('chunk-size'));

        if ($this->dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        } else {
            $this->info('Extracting places from google_nearby_search_cache and syncing to places table...');
        }

        if (! DB::getSchemaBuilder()->hasTable('google_nearby_search_cache')) {
            $this->error('Table google_nearby_search_cache does not exist.');

            return self::FAILURE;
        }

        $totalCacheRows = GoogleNearbySearchCache::count();
        $this->info("Total cache records found: {$totalCacheRows}");

        if ($totalCacheRows === 0) {
            $this->info('No cached nearby searches found. Nothing to extract.');

            return self::SUCCESS;
        }

        // Step 1: Scan and extract unique normalized places from google_nearby_search_cache
        $this->info('Scanning cached search results...');

        $scannedRows = 0;
        $totalRawPlaces = 0;
        /** @var array<string, array<string, mixed>> $extractedPlaces */
        $extractedPlaces = [];

        GoogleNearbySearchCache::query()
            ->orderBy('id')
            ->chunk($chunkSize, function ($caches) use (&$scannedRows, &$totalRawPlaces, &$extractedPlaces): void {
                foreach ($caches as $cache) {
                    $scannedRows++;
                    $rawPlaces = $cache->response_places;

                    if (! is_array($rawPlaces) || empty($rawPlaces)) {
                        continue;
                    }

                    foreach ($rawPlaces as $rawPlace) {
                        if (! is_array($rawPlace)) {
                            continue;
                        }

                        $totalRawPlaces++;
                        $placeId = $this->extractPlaceId($rawPlace);

                        if ($placeId === null || $placeId === '') {
                            continue;
                        }

                        $normalized = $this->normalizePlaceData($placeId, $rawPlace);

                        if (isset($extractedPlaces[$placeId])) {
                            $extractedPlaces[$placeId] = $this->mergePlaceData($extractedPlaces[$placeId], $normalized);
                        } else {
                            $extractedPlaces[$placeId] = $normalized;
                        }
                    }
                }
            });

        $uniquePlacesCount = count($extractedPlaces);
        $this->line("Extracted {$totalRawPlaces} place occurrences from {$scannedRows} cache rows ({$uniquePlacesCount} unique places).");

        if ($uniquePlacesCount === 0) {
            $this->info('No valid places found in cache entries.');

            return self::SUCCESS;
        }

        // Step 2: Compare against places table and add/update
        $this->info('Comparing extracted places against places table...');

        $addedCount = 0;
        $updatedCount = 0;
        $gainedOpeningHoursCount = 0;
        $gainedWebsiteCount = 0;
        $gainedOtherCount = 0;
        $unchangedCount = 0;
        $toiletsUpdatedCount = 0;

        $placeIds = array_keys($extractedPlaces);
        $idChunks = array_chunk($placeIds, 250);

        foreach ($idChunks as $idChunk) {
            /** @var \Illuminate\Database\Eloquent\Collection<string, Place> $existingPlaces */
            $existingPlaces = Place::whereIn('place_id', $idChunk)->get()->keyBy('place_id');

            foreach ($idChunk as $placeId) {
                $cachedData = $extractedPlaces[$placeId];
                $existing = $existingPlaces->get($placeId);

                if ($existing === null) {
                    // New place: Add to places table
                    $addedCount++;

                    if (! $this->dryRun) {
                        Place::create([
                            'place_id' => $placeId,
                            'data' => $cachedData,
                            'updated' => now(),
                        ]);
                    }

                    if ($this->updateToilets) {
                        $toiletsUpdatedCount += $this->syncToiletsForPlace($placeId, $cachedData, true, true);
                    }
                } else {
                    // Existing place: Check for missing fields
                    $existingData = is_array($existing->data) ? $existing->data : (json_decode((string) $existing->data, true) ?: []);

                    $gainedOpeningHours = ! $this->hasOpeningHours($existingData) && $this->hasOpeningHours($cachedData);
                    $gainedWebsite = ! $this->hasWebsite($existingData) && $this->hasWebsite($cachedData);
                    $gainedDisplayName = ! $this->hasDisplayName($existingData) && $this->hasDisplayName($cachedData);
                    $gainedAddress = ! $this->hasAddress($existingData) && $this->hasAddress($cachedData);
                    $gainedLocation = ! $this->hasLocation($existingData) && $this->hasLocation($cachedData);
                    $gainedTypes = empty($existingData['types'] ?? []) && ! empty($cachedData['types']);

                    $mergedData = $this->mergePlaceData($existingData, $cachedData);
                    $hasAnyGain = $gainedOpeningHours || $gainedWebsite || $gainedDisplayName || $gainedAddress || $gainedLocation || $gainedTypes;
                    $isChanged = ($mergedData != $existingData) && ($hasAnyGain || $this->force);

                    if ($isChanged) {
                        $updatedCount++;

                        if ($gainedOpeningHours) {
                            $gainedOpeningHoursCount++;
                        }
                        if ($gainedWebsite) {
                            $gainedWebsiteCount++;
                        }
                        if (! $gainedOpeningHours && ! $gainedWebsite) {
                            $gainedOtherCount++;
                        }

                        if (! $this->dryRun) {
                            $existing->data = $mergedData;
                            $existing->updated = now();
                            $existing->save();
                        }

                        if ($this->updateToilets) {
                            $toiletsUpdatedCount += $this->syncToiletsForPlace(
                                $placeId,
                                $mergedData,
                                $gainedOpeningHours,
                                $gainedWebsite
                            );
                        }
                    } else {
                        $unchangedCount++;
                    }
                }
            }
        }

        // Summary Table
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Cache records scanned', $scannedRows],
                ['Total places in cache records', $totalRawPlaces],
                ['Unique place IDs found', $uniquePlacesCount],
                [$this->dryRun ? 'Places to add (new)' : 'Places added (new)', $addedCount],
                [$this->dryRun ? 'Places to update' : 'Places updated', $updatedCount],
                ['  ├─ Gained regularOpeningHours', $gainedOpeningHoursCount],
                ['  ├─ Gained websiteUri', $gainedWebsiteCount],
                ['  └─ Other fields enriched', $gainedOtherCount],
                ['Places unchanged (already up to date)', $unchangedCount],
                [$this->dryRun ? 'Associated toilets to sync' : 'Associated toilets synced', $toiletsUpdatedCount],
            ]
        );

        if ($this->dryRun) {
            $this->info('Dry run completed. No database changes were made.');
        } else {
            $this->info('Place extraction and synchronization completed successfully.');
        }

        return self::SUCCESS;
    }

    /**
     * Extract a clean place ID string from various Google Places API formats.
     */
    public function extractPlaceId(array $place): ?string
    {
        $id = $place['id'] ?? $place['place_id'] ?? null;

        if (is_string($id) && trim($id) !== '') {
            $id = trim($id);
            if (str_starts_with($id, 'places/')) {
                $id = substr($id, strlen('places/'));
            }

            return $id !== '' ? $id : null;
        }

        if (isset($place['name']) && is_string($place['name']) && str_starts_with($place['name'], 'places/')) {
            $extracted = substr($place['name'], strlen('places/'));

            return $extracted !== '' ? $extracted : null;
        }

        return null;
    }

    /**
     * Normalize a place record to the Places API (New) schema format.
     *
     * @return array<string, mixed>
     */
    public function normalizePlaceData(string $placeId, array $data): array
    {
        $id = $data['id'] ?? $data['place_id'] ?? $placeId;
        if (is_string($id) && str_starts_with($id, 'places/')) {
            $id = substr($id, strlen('places/'));
        }

        // Display Name
        if (isset($data['displayName']['text']) && is_string($data['displayName']['text']) && trim($data['displayName']['text']) !== '') {
            $displayName = [
                'text' => trim($data['displayName']['text']),
                'languageCode' => (string) ($data['displayName']['languageCode'] ?? 'de'),
            ];
        } elseif (isset($data['displayName']) && is_string($data['displayName']) && trim($data['displayName']) !== '') {
            $displayName = ['text' => trim($data['displayName']), 'languageCode' => 'de'];
        } elseif (! empty($data['name']) && is_string($data['name']) && ! str_starts_with($data['name'], 'places/')) {
            $displayName = ['text' => trim($data['name']), 'languageCode' => 'de'];
        } else {
            $displayName = null;
        }

        // Formatted Address
        $formattedAddress = $data['formattedAddress'] ?? $data['formatted_address'] ?? null;
        if (is_string($formattedAddress)) {
            $formattedAddress = trim($formattedAddress) ?: null;
        } else {
            $formattedAddress = null;
        }

        // Coordinates / Location
        $lat = $data['location']['latitude'] ?? $data['location']['lat'] ?? $data['geometry']['location']['lat'] ?? $data['lat'] ?? null;
        $lng = $data['location']['longitude'] ?? $data['location']['lng'] ?? $data['geometry']['location']['lng'] ?? $data['lon'] ?? null;
        $location = ($lat !== null && $lng !== null) ? [
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ] : null;

        // Website URI
        $websiteUri = $data['websiteUri'] ?? $data['website'] ?? null;
        if (is_string($websiteUri)) {
            $websiteUri = trim($websiteUri) ?: null;
        } else {
            $websiteUri = null;
        }

        // Opening Hours
        $rawPeriods = $data['regularOpeningHours']['periods']
            ?? $data['opening_hours']['periods']
            ?? $data['result']['opening_hours']['periods']
            ?? null;

        $regularOpeningHours = null;
        if (is_array($rawPeriods)) {
            $periods = PlaceToiletService::normalizePeriodPoints($rawPeriods);
            if (count($periods) > 0) {
                $regularOpeningHours = ['periods' => $periods];
                if (isset($data['regularOpeningHours']['openNow']) || isset($data['opening_hours']['open_now'])) {
                    $regularOpeningHours['openNow'] = (bool) ($data['regularOpeningHours']['openNow'] ?? $data['opening_hours']['open_now'] ?? false);
                }
                if (isset($data['regularOpeningHours']['weekdayDescriptions']) || isset($data['opening_hours']['weekday_text'])) {
                    $regularOpeningHours['weekdayDescriptions'] = (array) ($data['regularOpeningHours']['weekdayDescriptions'] ?? $data['opening_hours']['weekday_text'] ?? []);
                }
            }
        }

        // Types
        $types = $data['types'] ?? null;
        if ($types === null && isset($data['type'])) {
            $types = is_array($data['type']) ? $data['type'] : [$data['type']];
        }
        if (is_array($types)) {
            $types = array_values(array_filter(array_map('strval', $types)));
        } else {
            $types = null;
        }

        $result = ['id' => $id];

        if ($displayName !== null) {
            $result['displayName'] = $displayName;
        }
        if ($formattedAddress !== null) {
            $result['formattedAddress'] = $formattedAddress;
        }
        if ($location !== null) {
            $result['location'] = $location;
        }
        if ($websiteUri !== null) {
            $result['websiteUri'] = $websiteUri;
        }
        if ($regularOpeningHours !== null) {
            $result['regularOpeningHours'] = $regularOpeningHours;
        }
        if ($types !== null && count($types) > 0) {
            $result['types'] = $types;
        }

        return $result;
    }

    /**
     * Merge existing place data with incoming cached place data, filling in missing or richer fields.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public function mergePlaceData(array $existing, array $incoming): array
    {
        $merged = $existing;

        // ID
        $merged['id'] = $incoming['id'] ?? $existing['id'] ?? ($existing['place_id'] ?? null);

        // Display Name: fill if missing
        if (! $this->hasDisplayName($existing) && $this->hasDisplayName($incoming)) {
            $merged['displayName'] = $incoming['displayName'];
        }

        // Formatted Address: fill if missing
        if (! $this->hasAddress($existing) && $this->hasAddress($incoming)) {
            $merged['formattedAddress'] = $incoming['formattedAddress'];
        }

        // Location: fill if missing
        if (! $this->hasLocation($existing) && $this->hasLocation($incoming)) {
            $merged['location'] = $incoming['location'];
        }

        // Website URI: fill if missing
        if (! $this->hasWebsite($existing) && $this->hasWebsite($incoming)) {
            $merged['websiteUri'] = $incoming['websiteUri'];
        }

        // Regular Opening Hours: fill if missing or if incoming has periods
        if (! $this->hasOpeningHours($existing) && $this->hasOpeningHours($incoming)) {
            $merged['regularOpeningHours'] = $incoming['regularOpeningHours'];
        }

        // Types: merge and deduplicate
        $existingTypes = is_array($existing['types'] ?? null) ? $existing['types'] : [];
        $incomingTypes = is_array($incoming['types'] ?? null) ? $incoming['types'] : [];
        $combinedTypes = array_values(array_unique(array_merge($existingTypes, $incomingTypes)));
        if (! empty($combinedTypes)) {
            $merged['types'] = $combinedTypes;
        }

        return $merged;
    }

    public function hasDisplayName(array $data): bool
    {
        if (isset($data['displayName']['text']) && is_string($data['displayName']['text']) && trim($data['displayName']['text']) !== '') {
            return true;
        }
        if (isset($data['displayName']) && is_string($data['displayName']) && trim($data['displayName']) !== '') {
            return true;
        }
        if (! empty($data['name']) && is_string($data['name']) && ! str_starts_with($data['name'], 'places/')) {
            return true;
        }

        return false;
    }

    public function hasAddress(array $data): bool
    {
        $address = $data['formattedAddress'] ?? $data['formatted_address'] ?? null;

        return is_string($address) && trim($address) !== '';
    }

    public function hasLocation(array $data): bool
    {
        $lat = $data['location']['latitude'] ?? $data['location']['lat'] ?? $data['geometry']['location']['lat'] ?? $data['lat'] ?? null;
        $lng = $data['location']['longitude'] ?? $data['location']['lng'] ?? $data['geometry']['location']['lng'] ?? $data['lon'] ?? null;

        return $lat !== null && $lng !== null;
    }

    public function hasWebsite(array $data): bool
    {
        $website = $data['websiteUri'] ?? $data['website'] ?? null;

        return is_string($website) && trim($website) !== '';
    }

    public function hasOpeningHours(array $data): bool
    {
        $periods = $data['regularOpeningHours']['periods']
            ?? $data['opening_hours']['periods']
            ?? $data['result']['opening_hours']['periods']
            ?? null;

        return is_array($periods) && count($periods) > 0;
    }

    /**
     * Sync opening hours and website properties to toilets linked to the given place ID.
     */
    private function syncToiletsForPlace(
        string $placeId,
        array $placeData,
        bool $gainedOpeningHours,
        bool $gainedWebsite
    ): int {
        if (! $gainedOpeningHours && ! $gainedWebsite) {
            return 0;
        }

        $toilets = Toilet::where('place_id', $placeId)->get();
        $updated = 0;

        foreach ($toilets as $toilet) {
            $diff = [];

            if ($gainedOpeningHours && isset($placeData['regularOpeningHours']['periods'])) {
                $hasHoursProp = DB::table('toilet_properties')
                    ->where('fk_toiletId', $toilet->id)
                    ->where('type', 'place_opening_hours')
                    ->exists();

                if (! $hasHoursProp) {
                    $encodedPeriods = json_encode($placeData['regularOpeningHours']['periods']);
                    $diff['place_opening_hours'] = ['old' => null, 'new' => $encodedPeriods];

                    if (! $this->dryRun) {
                        DB::table('toilet_properties')->insert([
                            'fk_toiletId' => $toilet->id,
                            'type' => 'place_opening_hours',
                            'value' => $encodedPeriods,
                        ]);
                    }
                }
            }

            if ($gainedWebsite && ! empty($placeData['websiteUri'])) {
                $hasWebsiteProp = DB::table('toilet_properties')
                    ->where('fk_toiletId', $toilet->id)
                    ->where('type', 'website')
                    ->exists();

                if (! $hasWebsiteProp) {
                    $diff['website'] = ['old' => null, 'new' => $placeData['websiteUri']];

                    if (! $this->dryRun) {
                        DB::table('toilet_properties')->insert([
                            'fk_toiletId' => $toilet->id,
                            'type' => 'website',
                            'value' => $placeData['websiteUri'],
                        ]);
                    }
                }
            }

            if (! empty($diff)) {
                $updated++;

                if (! $this->dryRun) {
                    $toilet->last_diff = $diff;
                    $toilet->save();

                    $this->revisionService->recordRevision(
                        $toilet,
                        'extract-nearby-cache',
                        $diff,
                        "Synced missing properties from nearby search cache ({$placeId})"
                    );
                }
            }
        }

        return $updated;
    }
}
