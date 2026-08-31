<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Toilet;
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
                            {--dry-run : Show what would be changed without writing anything}';

    protected $description = 'Update places for toilets that have been requested by API clients';

    private bool $dryRun;

    private int $limit;

    private int $radius;

    private int $processed = 0;

    private int $insertedPlaces = 0;

    private int $updatedPlaces = 0;

    private int $insertedToilets = 0;

    private int $updatedToilets = 0;

    private int $skippedCached = 0;

    private int $errors = 0;

    public function handle(GooglePlacesService $placesService, PlaceToiletService $placeToiletService): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
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
            $toilets = (clone $query)->limit($this->limit)->get(['id', 'place_id', 'last_included', 'last_discovered']);
            $this->info("Found {$toilets->count()} toilets to discover.");

            foreach ($toilets as $toilet) {
                $this->line("[DRY-RUN] toilet_id={$toilet->id} place_id={$toilet->place_id} last_included={$toilet->last_included} last_discovered={$toilet->last_discovered}");
                $this->processed++;
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
            ->whereNotNull('place_id')
            ->orderBy('last_included', 'desc');
    }

    private function processToilet(GooglePlacesService $placesService, PlaceToiletService $placeToiletService, Toilet $toilet): void
    {
        $details = $placesService->fetchPlaceDetails($toilet->place_id);

        if (! $details) {
            $this->errors++;
            $this->error("Google Place Details returned no result for toilet {$toilet->id} (place_id={$toilet->place_id})");

            return;
        }

        $place = Place::where('place_id', $toilet->place_id)->first();

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

        $toilet->update(['last_discovered' => now()]);
    }

    private function logSummary(): void
    {
        $summary = [
            'action' => 'discover-places',
            'dry_run' => $this->dryRun,
            'limit' => $this->limit,
            'processed' => $this->processed,
            'inserted_places' => $this->insertedPlaces,
            'updated_places' => $this->updatedPlaces,
            'inserted_toilets' => $this->insertedToilets,
            'updated_toilets' => $this->updatedToilets,
            'skipped_cached' => $this->skippedCached,
            'errors' => $this->errors,
        ];

        $this->newLine();
        $this->info('Discovery summary:');
        foreach ($summary as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        Log::info('DiscoverPlaces completed', $summary);
    }
}
