<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Toilet;
use App\Services\GoogleCostService;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use App\Services\ToiletRevisionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DiscoverPlacesCommand extends Command
{
    protected $signature = 'app:discover-places
                            {--lat= : Optional override: latitude of the search center}
                            {--lon= : Optional override: longitude of the search center}
                            {--radius= : Search radius in meters when lat/lon is provided (default from config)}
                            {--limit= : Maximum number of toilets to process}
                            {--prefer-cache= : Prefer cached data from places table instead of querying Google Places API. Optionally specify max cache age (e.g. --prefer-cache=30d, --prefer-cache=6m, --prefer-cache=1y)}
                            {--max-cache-age= : Max allowed cache age when preferring cache (e.g. 30d, 6m, 1y)}
                            {--dry-run : Show what would be changed without writing anything}';

    protected $description = 'Update places for toilets that have been requested by API clients';

    private bool $dryRun;

    private bool $preferCache;

    private ?string $maxCacheAgeString = null;

    private ?Carbon $cacheCutoff = null;

    private int $limit;

    private int $radius;

    private int $processed = 0;

    private int $toiletsNeedingDiscoveryCount = 0;

    private int $totalNeedingDiscoveryCount = 0;

    private int $predictedApiCalls = 0;

    private int $predictedCachedPlaces = 0;

    private float $predictedCostUsd = 0.0;

    private int $totalPredictedApiCalls = 0;

    private float $totalPredictedCostUsd = 0.0;

    private int $cachedPlaces = 0;

    private int $insertedPlaces = 0;

    private int $updatedPlaces = 0;

    private int $insertedToilets = 0;

    private int $updatedToilets = 0;

    private int $skippedCached = 0;

    private int $unlinkedPlaces = 0;

    private int $errors = 0;

    public function handle(
        GooglePlacesService $placesService,
        PlaceToiletService $placeToiletService,
        ToiletRevisionService $revisionService
    ): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $preferCacheOption = $this->option('prefer-cache');
        $maxCacheAgeOption = $this->option('max-cache-age');

        // Defaults to 30d (prefer-cache enabled by default) unless explicitly disabled
        $this->preferCache = true;
        if ($preferCacheOption === false || $preferCacheOption === 'false' || $preferCacheOption === '0') {
            $this->preferCache = false;
        }

        $ageString = null;
        if ($this->preferCache) {
            if ($maxCacheAgeOption !== null) {
                $ageString = (string) $maxCacheAgeOption;
            } elseif (is_string($preferCacheOption) && ! in_array(strtolower($preferCacheOption), ['true', '1'], true)) {
                $ageString = $preferCacheOption;
            } else {
                $ageString = '30d';
            }
        }

        $this->maxCacheAgeString = $ageString;
        $this->cacheCutoff = $this->parseCacheMaxAgeCutoff($ageString);

        $this->limit = (int) $this->option('limit') ?: (int) config('wcinfo.discover.limit', 500);
        $this->radius = (int) $this->option('radius') ?: (int) config('wcinfo.discover.radius', 2000);

        if ($this->dryRun) {
            $this->warn('DRY-RUN mode: no data will be written.');
        }

        $lat = $this->option('lat') ? (float) $this->option('lat') : null;
        $lon = $this->option('lon') ? (float) $this->option('lon') : null;

        try {
            if ($lat !== null && $lon !== null) {
                $this->info("Discovering places around {$lat}, {$lon} with radius {$this->radius}m, limit {$this->limit}");
                $this->processArea($placesService, $placeToiletService, $lat, $lon);
            } else {
                $this->info("Processing up to {$this->limit} toilets that were requested but not yet discovered");
                $this->processRequestedToilets($placesService, $placeToiletService, $revisionService);
            }
        } catch (\Throwable $e) {
            $this->error('Discovery failed: '.$e->getMessage());
            Log::error('DiscoverPlaces failed', ['error' => $e->getMessage()]);
            $this->errors++;
            $this->logSummary();

            return self::FAILURE;
        }

        $this->logSummary();

        return self::SUCCESS;
    }

    private function processArea(GooglePlacesService $placesService, PlaceToiletService $placeToiletService, float $lat, float $lon): void
    {
        if ($this->dryRun) {
            $results = $placesService->nearbySearchRaw($lat, $lon, $this->radius / 1000);
            $this->info('Google returned '.count($results).' raw results.');

            foreach (array_slice($results, 0, $this->limit) as $result) {
                $placeId = $result['place_id'] ?? null;
                $name = $result['name'] ?? '';
                $this->line("[DRY-RUN] place_id={$placeId} name=\"{$name}\"");
                $this->processed++;
            }

            return;
        }

        $activeToilets = $placesService->discoverToiletsNearby($lat, $lon, $this->radius / 1000, $this->limit);

        $this->info('Discovery returned '.$activeToilets->count().' active toilets.');
        $this->insertedToilets += $activeToilets->count();
    }

    private function processRequestedToilets(
        GooglePlacesService $placesService,
        PlaceToiletService $placeToiletService,
        ToiletRevisionService $revisionService
    ): void {
        $query = $this->toiletsNeedingDiscovery();

        if ($this->dryRun) {
            $this->totalNeedingDiscoveryCount = (clone $query)->count();
            $toilets = (clone $query)->limit($this->limit)->get(['id', 'place_id', 'last_included', 'last_discovered']);
            $this->toiletsNeedingDiscoveryCount = $this->totalNeedingDiscoveryCount;

            $this->info("Found {$toilets->count()} toilets to discover." . ($this->totalNeedingDiscoveryCount > $toilets->count() ? " ({$this->totalNeedingDiscoveryCount} total in database needing discovery)" : ''));

            if ($toilets->isEmpty()) {
                return;
            }

            $placeIds = $toilets->pluck('place_id')->filter()->unique()->values()->all();
            $cachedPlaces = [];
            if ($this->preferCache && ! empty($placeIds)) {
                $cachedPlaces = Place::whereIn('place_id', $placeIds)
                    ->whereNotNull('data')
                    ->get()
                    ->filter(fn (Place $p) => ! empty($p->data))
                    ->keyBy('place_id');
            }

            $seenPlaceIdsInRun = [];

            foreach ($toilets as $toilet) {
                $placeId = $toilet->place_id;

                if (empty($placeId)) {
                    $this->errors++;
                    $this->line("[DRY-RUN] toilet_id={$toilet->id} (missing place_id)");

                    continue;
                }

                $this->processed++;

                $isCached = false;
                if ($this->preferCache) {
                    if (isset($cachedPlaces[$placeId])) {
                        if ($this->isCacheFresh($cachedPlaces[$placeId], $toilet)) {
                            $isCached = true;
                        }
                    } elseif (isset($seenPlaceIdsInRun[$placeId])) {
                        $isCached = true;
                    }
                }

                if ($isCached) {
                    $this->predictedCachedPlaces++;
                    $statusTag = '<comment>[CACHED]</comment>';
                } else {
                    $this->predictedApiCalls++;
                    $seenPlaceIdsInRun[$placeId] = true;
                    $staleNotice = (isset($cachedPlaces[$placeId]) && ! $this->isCacheFresh($cachedPlaces[$placeId], $toilet))
                        ? ' (stale cache)'
                        : '';
                    $statusTag = "<info>[API CALL{$staleNotice}]</info>";
                }

                $this->line("[DRY-RUN] toilet_id={$toilet->id} place_id={$toilet->place_id} last_included={$toilet->last_included} last_discovered={$toilet->last_discovered} {$statusTag}");
            }

            $costPerCall = GoogleCostService::COST_PLACES_DETAILS_USD;
            $this->predictedCostUsd = $this->predictedApiCalls * $costPerCall;

            if ($this->totalNeedingDiscoveryCount > $toilets->count()) {
                if ($this->preferCache) {
                    $allPlaceIds = (clone $query)->pluck('place_id')->filter()->unique()->values()->all();
                    $placesQuery = Place::whereIn('place_id', $allPlaceIds)
                        ->whereNotNull('data');

                    if ($this->cacheCutoff !== null) {
                        $placesQuery->where('updated', '>=', $this->cacheCutoff);
                    }

                    $totalCachedCount = $placesQuery
                        ->get()
                        ->filter(fn (Place $p) => ! empty($p->data))
                        ->count();
                    $this->totalPredictedApiCalls = count($allPlaceIds) - $totalCachedCount;
                    $this->totalPredictedCostUsd = $this->totalPredictedApiCalls * $costPerCall;
                } else {
                    $this->totalPredictedApiCalls = $this->totalNeedingDiscoveryCount;
                    $this->totalPredictedCostUsd = $this->totalPredictedApiCalls * $costPerCall;
                }
            } else {
                $this->totalPredictedApiCalls = $this->predictedApiCalls;
                $this->totalPredictedCostUsd = $this->predictedCostUsd;
            }

            return;
        }

        $toilets = $query->take($this->limit)->get();

        foreach ($toilets as $toilet) {
            $this->processed++;

            if (empty($toilet->place_id)) {
                $this->errors++;
                $this->error("Skipping toilet {$toilet->id}: missing place_id");

                continue;
            }

            try {
                $this->processToilet($placesService, $placeToiletService, $revisionService, $toilet);
            } catch (\Throwable $e) {
                $this->errors++;
                $message = "Failed to process toilet {$toilet->id} (place_id={$toilet->place_id}): ".$e->getMessage();
                $this->error($message);
                Log::warning('DiscoverPlaces failed for toilet', [
                    'toilet_id' => $toilet->id,
                    'place_id' => $toilet->place_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function toiletsNeedingDiscovery()
    {
        return Toilet::query()
            ->whereNotNull('last_included')
            ->where(function ($query) {
                $query->whereNull('last_discovered')
                    ->orWhereColumn('last_included', '>', 'last_discovered');
            })
            ->whereNotNull('place_id')
            ->where('status', 'active')
            ->orderBy('last_included', 'desc');
    }

    private function processToilet(
        GooglePlacesService $placesService,
        PlaceToiletService $placeToiletService,
        ToiletRevisionService $revisionService,
        Toilet $toilet
    ): void {
        $place = Place::where('place_id', $toilet->place_id)->first();
        $details = null;
        $fromCache = false;

        if ($this->preferCache && $place && ! empty($place->data) && $this->isCacheFresh($place, $toilet)) {
            $details = is_array($place->data) ? $place->data : json_decode((string) $place->data, true);
            $fromCache = true;
        }

        if (! $details) {
            $details = $placesService->fetchPlaceDetails($toilet->place_id);
        }

        if (! $details) {
            $oldPlaceId = $toilet->place_id;
            $changes = [
                'place_id' => ['old' => $oldPlaceId, 'new' => null],
                'flagged' => ['old' => (bool) $toilet->flagged, 'new' => true],
            ];

            $toilet->update([
                'place_id' => null,
                'flagged' => true,
                'last_diff' => $changes,
                'last_discovered' => now(),
                'last_places_fetch' => now(),
            ]);

            $revisionService->recordRevision(
                $toilet,
                'discover-places-not-found',
                $changes,
                "Place {$oldPlaceId} not found in Google Places API; removed place_id and flagged for review"
            );

            $this->unlinkedPlaces++;
            $this->warn("Google Place Details returned no result for toilet {$toilet->id} (place_id={$oldPlaceId}): removed place_id and flagged for review");
            Log::info("DiscoverPlaces: Place {$oldPlaceId} not found for toilet {$toilet->id}; removed place_id and flagged", [
                'toilet_id' => $toilet->id,
                'old_place_id' => $oldPlaceId,
            ]);

            return;
        }

        if ($fromCache) {
            $this->cachedPlaces++;
        } else {
            if ($place) {
                $place->update([
                    'data' => $details,
                    'updated' => now(),
                ]);
                $this->updatedPlaces++;
            } else {
                Place::create([
                    'place_id' => $toilet->place_id,
                    'data' => $details,
                    'updated' => now(),
                ]);
                $this->insertedPlaces++;
            }
        }

        $existingToilet = Toilet::where('place_id', $toilet->place_id)->first();

        if ($existingToilet) {
            $changes = [];
            $updated = $placeToiletService->updateToiletFromPlace($existingToilet, $details, $details, $changes);

            if ($updated) {
                $this->updatedToilets++;
                Log::debug("Updated toilet {$existingToilet->id} from place {$toilet->place_id}", [
                    'toilet_id' => $existingToilet->id,
                    'place_id' => $toilet->place_id,
                    'changes' => $changes,
                ]);
            } else {
                $this->skippedCached++;
            }
        } else {
            $created = $placeToiletService->createToiletFromPlace($details, $details);

            if ($created) {
                $this->insertedToilets++;
            }
        }

        $toilet->update([
            'last_discovered' => now(),
            'last_places_fetch' => now(),
        ]);
    }

    private function logSummary(): void
    {
        $summary = [
            'action' => 'discover-places',
            'dry_run' => $this->dryRun,
            'prefer_cache' => $this->preferCache,
            'max_cache_age' => $this->maxCacheAgeString ?? 'none',
            'limit' => $this->limit,
            'processed' => $this->processed,
        ];

        if ($this->dryRun) {
            $summary['toilets_needing_discovery'] = $this->toiletsNeedingDiscoveryCount;
            $summary['predicted_api_calls'] = $this->predictedApiCalls;
            $summary['predicted_cached_places'] = $this->predictedCachedPlaces;
            $summary['predicted_cost_usd'] = round($this->predictedCostUsd, 4);

            if ($this->totalNeedingDiscoveryCount > $this->processed) {
                $summary['total_toilets_needing_discovery'] = $this->totalNeedingDiscoveryCount;
                $summary['total_predicted_api_calls'] = $this->totalPredictedApiCalls;
                $summary['total_predicted_cost_usd'] = round($this->totalPredictedCostUsd, 4);
            }
        }

        $summary['cached_places'] = $this->cachedPlaces;
        $summary['inserted_places'] = $this->insertedPlaces;
        $summary['updated_places'] = $this->updatedPlaces;
        $summary['inserted_toilets'] = $this->insertedToilets;
        $summary['updated_toilets'] = $this->updatedToilets;
        $summary['skipped_cached'] = $this->skippedCached;
        $summary['unlinked_places'] = $this->unlinkedPlaces;
        $summary['errors'] = $this->errors;

        $this->newLine();
        $this->info('Discovery summary:');
        foreach ($summary as $key => $value) {
            $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            $this->line("  {$key}: {$displayValue}");
        }

        if ($this->dryRun) {
            $this->newLine();
            $this->info(sprintf(
                '💰 Cost Prediction: %d Google API call(s) required (~$%s USD)',
                $this->predictedApiCalls,
                number_format($this->predictedCostUsd, 3)
            ));
            if ($this->preferCache) {
                $ageInfo = $this->maxCacheAgeString ? " with max cache age: {$this->maxCacheAgeString}" : '';
                $this->comment(sprintf(
                    '   (Prefer-cache saved %d API call(s) ~$%s USD%s)',
                    $this->predictedCachedPlaces,
                    number_format($this->predictedCachedPlaces * GoogleCostService::COST_PLACES_DETAILS_USD, 3),
                    $ageInfo
                ));
            }
        }

        Log::info('DiscoverPlaces completed', $summary);
    }

    /**
     * Check if cached place data satisfies the maximum allowed cache age.
     */
    private function isCacheFresh(Place $place, ?Toilet $toilet = null): bool
    {
        if ($this->cacheCutoff === null) {
            return true;
        }

        $timestamp = $place->updated ?? ($toilet?->last_places_fetch ? Carbon::parse($toilet->last_places_fetch) : null);

        if ($timestamp === null) {
            return false;
        }

        $carbon = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);

        return $carbon->gte($this->cacheCutoff);
    }

    /**
     * Parse a human-readable duration string into a Carbon cutoff date.
     * Supported formats:
     * - 30d, 30days, 30day, 30 -> 30 days ago
     * - 2w, 2weeks, 2week     -> 2 weeks ago
     * - 1m, 1month, 1months, 6m -> 1 or 6 months ago
     * - 1y, 1year, 1years     -> 1 year ago
     * - 24h, 24hours          -> 24 hours ago
     */
    private function parseCacheMaxAgeCutoff(?string $ageString): ?Carbon
    {
        if ($ageString === null || $ageString === '' || in_array(strtolower($ageString), ['true', '1', 'all', 'infinite'], true)) {
            return null;
        }

        $trimmed = trim(strtolower($ageString));

        if (preg_match('/^(\d+)\s*(d|day|days)?$/', $trimmed, $m)) {
            return Carbon::now()->subDays((int) $m[1]);
        }

        if (preg_match('/^(\d+)\s*(w|week|weeks)$/', $trimmed, $m)) {
            return Carbon::now()->subWeeks((int) $m[1]);
        }

        if (preg_match('/^(\d+)\s*(m|month|months)$/', $trimmed, $m)) {
            return Carbon::now()->subMonths((int) $m[1]);
        }

        if (preg_match('/^(\d+)\s*(y|year|years)$/', $trimmed, $m)) {
            return Carbon::now()->subYears((int) $m[1]);
        }

        if (preg_match('/^(\d+)\s*(h|hour|hours)$/', $trimmed, $m)) {
            return Carbon::now()->subHours((int) $m[1]);
        }

        throw new \InvalidArgumentException("Invalid cache max age format: '{$ageString}'. Use formats like '30d', '2w', '6m', or '1y'.");
    }
}
