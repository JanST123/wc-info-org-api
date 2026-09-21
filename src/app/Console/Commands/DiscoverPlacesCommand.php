<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Toilet;
use App\Services\GoogleCostService;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DiscoverPlacesCommand extends Command
{
    protected $signature = 'app:discover-places
                            {--lat= : Optional override: latitude of the search center}
                            {--lon= : Optional override: longitude of the search center}
                            {--radius= : Search radius in meters when lat/lon is provided (default from config)}
                            {--limit= : Maximum number of toilets to process}
                            {--prefer-cache : Always use existing data from the places table if available instead of querying Google Places API}
                            {--dry-run : Show what would be changed without writing anything}';

    protected $description = 'Update places for toilets that have been requested by API clients';

    private bool $dryRun;

    private bool $preferCache;

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

    private int $errors = 0;

    public function handle(GooglePlacesService $placesService, PlaceToiletService $placeToiletService): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->preferCache = (bool) $this->option('prefer-cache');
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
                $this->processRequestedToilets($placesService, $placeToiletService);
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

    private function processRequestedToilets(GooglePlacesService $placesService, PlaceToiletService $placeToiletService): void
    {
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
                    ->pluck('place_id')
                    ->flip()
                    ->all();
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
                    if (isset($cachedPlaces[$placeId]) || isset($seenPlaceIdsInRun[$placeId])) {
                        $isCached = true;
                    }
                }

                if ($isCached) {
                    $this->predictedCachedPlaces++;
                    $statusTag = '<comment>[CACHED]</comment>';
                } else {
                    $this->predictedApiCalls++;
                    $seenPlaceIdsInRun[$placeId] = true;
                    $statusTag = '<info>[API CALL]</info>';
                }

                $this->line("[DRY-RUN] toilet_id={$toilet->id} place_id={$toilet->place_id} last_included={$toilet->last_included} last_discovered={$toilet->last_discovered} {$statusTag}");
            }

            $costPerCall = GoogleCostService::COST_PLACES_DETAILS_USD;
            $this->predictedCostUsd = $this->predictedApiCalls * $costPerCall;

            if ($this->totalNeedingDiscoveryCount > $toilets->count()) {
                if ($this->preferCache) {
                    $allPlaceIds = (clone $query)->pluck('place_id')->filter()->unique()->values()->all();
                    $totalCachedCount = Place::whereIn('place_id', $allPlaceIds)
                        ->whereNotNull('data')
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
                $this->processToilet($placesService, $placeToiletService, $toilet);
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
            ->when(! $this->preferCache, function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('last_places_fetch')
                        ->orWhere('last_places_fetch', '<=', now()->subMonth());
                });
            })
            ->whereNotNull('place_id')
            ->orderBy('last_included', 'desc');
    }

    private function processToilet(GooglePlacesService $placesService, PlaceToiletService $placeToiletService, Toilet $toilet): void
    {
        $place = Place::where('place_id', $toilet->place_id)->first();
        $details = null;
        $fromCache = false;

        if ($this->preferCache && $place && ! empty($place->data)) {
            $details = is_array($place->data) ? $place->data : json_decode((string) $place->data, true);
            $fromCache = true;
        }

        if (! $details) {
            $details = $placesService->fetchPlaceDetails($toilet->place_id);
        }

        if (! $details) {
            $this->errors++;
            $this->error("Google Place Details returned no result for toilet {$toilet->id} (place_id={$toilet->place_id})");

            return;
        }

        if ($fromCache) {
            $this->cachedPlaces++;
        } else {
            if ($place) {
                $place->update(['data' => $details]);
                $this->updatedPlaces++;
            } else {
                Place::create([
                    'place_id' => $toilet->place_id,
                    'data' => $details,
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
        $summary['errors'] = $this->errors;

        $this->newLine();
        $this->info('Discovery summary:');
        foreach ($summary as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        if ($this->dryRun) {
            $this->newLine();
            $this->info(sprintf(
                '💰 Cost Prediction: %d Google API call(s) required (~$%s USD)',
                $this->predictedApiCalls,
                number_format($this->predictedCostUsd, 3)
            ));
            if ($this->preferCache) {
                $this->comment(sprintf(
                    '   (Prefer-cache saved %d API call(s) ~$%s USD)',
                    $this->predictedCachedPlaces,
                    number_format($this->predictedCachedPlaces * GoogleCostService::COST_PLACES_DETAILS_USD, 3)
                ));
            }
        }

        Log::info('DiscoverPlaces completed', $summary);
    }
}
