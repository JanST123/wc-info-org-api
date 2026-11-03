<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Toilet;
use App\Services\GoogleCostService;
use App\Services\GooglePlacesService;
use App\Services\ToiletRevisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairDuplicateCoordinateToiletsCommand extends Command
{
    protected $signature = 'app:repair-duplicate-coordinate-toilets
                            {--dry-run : Preview changes without writing to database}
                            {--skip-api : Only look up places in local places table, do not call Google Places API}
                            {--force : Overwrite coordinates even if toilet has lat/lon marked as user_overridden}
                            {--unflag : Unflag toilet when its coordinates are successfully updated from the place}
                            {--coords= : Only process toilets at specific coordinates (e.g. 50.0,7.0)}
                            {--limit= : Limit the number of duplicate toilets to process}';

    /**
     * @var array<int, string>
     */
    protected $aliases = ['app:fix-duplicate-coordinate-toilets'];

    protected $description = 'Find toilets sharing coordinates, restore missing place_id from revisions, and update coordinates from place';

    protected bool $budgetWarned = false;

    public function __construct(
        protected ToiletRevisionService $revisionService,
        protected GooglePlacesService $placesService,
        protected GoogleCostService $costService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $dryRun = (bool) $this->option('dry-run');
        $skipApi = (bool) $this->option('skip-api');
        $force = (bool) $this->option('force');
        $unflag = (bool) $this->option('unflag');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $coordsOption = $this->option('coords') ? trim((string) $this->option('coords')) : null;

        $targetLat = null;
        $targetLon = null;
        if ($coordsOption) {
            $parts = explode(',', $coordsOption);
            if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
                $targetLat = (float) trim($parts[0]);
                $targetLon = (float) trim($parts[1]);
            } else {
                $this->error('Invalid --coords format. Please provide lat,lon (e.g. --coords=50.0,7.0)');

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('🔍 DRY-RUN MODE: No changes will be written to the database.');
        }

        $this->info('Scanning for toilets with identical coordinates...');

        // 1. Identify distinct coordinate pairs that appear on more than one non-deleted toilet
        $dupCoordsSubQuery = DB::table('toilets')
            ->select('lat', 'lon')
            ->whereNotNull('lat')
            ->whereNotNull('lon')
            ->where('status', '!=', 'deleted')
            ->when($targetLat !== null && $targetLon !== null, function ($q) use ($targetLat, $targetLon) {
                $q->where('lat', $targetLat)->where('lon', $targetLon);
            })
            ->groupBy('lat', 'lon')
            ->havingRaw('count(*) > 1');

        $dupGroupsCount = (clone $dupCoordsSubQuery)->count();

        if ($dupGroupsCount === 0) {
            $this->info('No duplicate coordinate toilets found.');

            return self::SUCCESS;
        }

        $this->info("Found {$dupGroupsCount} coordinate groups with duplicate toilets.");

        // 2. Fetch duplicate toilets
        $toiletsQuery = Toilet::query()
            ->joinSub($dupCoordsSubQuery, 'dup_coords', function ($join) {
                $join->on('toilets.lat', '=', 'dup_coords.lat')
                    ->on('toilets.lon', '=', 'dup_coords.lon');
            })
            ->where('toilets.status', '!=', 'deleted')
            ->select('toilets.*')
            ->orderBy('toilets.lat')
            ->orderBy('toilets.lon')
            ->orderBy('toilets.id');

        if ($limit !== null && $limit > 0) {
            $toiletsQuery->limit($limit);
        }

        $toilets = $toiletsQuery->get();
        $this->line("Processing {$toilets->count()} duplicate toilets...");

        $placeCache = [];
        $totalProcessed = 0;
        $totalPlaceIdsRestored = 0;
        $totalCoordinatesUpdated = 0;
        $totalSkippedUserOverridden = 0;
        $totalSkippedCoordsMatch = 0;
        $totalFailedNoCoords = 0;
        $totalNoPlaceId = 0;
        $tableRows = [];

        foreach ($toilets as $toilet) {
            $totalProcessed++;
            $oldLat = $toilet->lat !== null ? (float) $toilet->lat : null;
            $oldLon = $toilet->lon !== null ? (float) $toilet->lon : null;
            $actions = [];

            // 1. If toilet has no place_id, attempt restoration from revisions
            if (empty($toilet->place_id)) {
                $earlierPlaceId = $this->findEarlierPlaceId($toilet->id);
                if ($earlierPlaceId) {
                    $toilet->place_id = $earlierPlaceId;
                    $totalPlaceIdsRestored++;
                    $actions[] = "Restored place_id ({$earlierPlaceId})";

                    if (! $dryRun) {
                        $diff = [
                            'place_id' => ['old' => null, 'new' => $earlierPlaceId],
                        ];
                        $toilet->last_diff = $diff;
                        $toilet->save();

                        $this->revisionService->recordRevision(
                            $toilet,
                            'restore_place_id',
                            $diff,
                            "Restored place_id {$earlierPlaceId} from previous revision"
                        );
                    }
                } else {
                    $totalNoPlaceId++;
                    $actions[] = 'No place_id available';
                    $tableRows[] = [
                        $toilet->id,
                        $toilet->name ?? "WC #{$toilet->id}",
                        "{$oldLat}, {$oldLon}",
                        '-',
                        '-',
                        implode('; ', $actions),
                    ];

                    continue;
                }
            }

            // 2. Retrieve coordinates from place
            $resolvedCoords = $this->resolvePlaceCoordinates($toilet->place_id, $skipApi, $dryRun, $placeCache);

            if (! $resolvedCoords) {
                $totalFailedNoCoords++;
                $actions[] = 'Place coordinates unavailable';
                $tableRows[] = [
                    $toilet->id,
                    $toilet->name ?? "WC #{$toilet->id}",
                    "{$oldLat}, {$oldLon}",
                    '-',
                    $toilet->place_id,
                    implode('; ', $actions),
                ];

                continue;
            }

            $newLat = $resolvedCoords['lat'];
            $newLon = $resolvedCoords['lon'];

            // 3. Check user_overridden
            $isOverridden = $toilet->isUserOverridden('lat') || $toilet->isUserOverridden('lon');
            if ($isOverridden && ! $force) {
                $totalSkippedUserOverridden++;
                $actions[] = 'Skipped (user_overridden)';
                $tableRows[] = [
                    $toilet->id,
                    $toilet->name ?? "WC #{$toilet->id}",
                    "{$oldLat}, {$oldLon}",
                    "{$newLat}, {$newLon}",
                    $toilet->place_id,
                    implode('; ', $actions),
                ];

                continue;
            }

            // 4. Check if coordinates actually differ
            $latDiffers = ! Toilet::areCoordinatesEqual($oldLat, $newLat);
            $lonDiffers = ! Toilet::areCoordinatesEqual($oldLon, $newLon);

            if (! $latDiffers && ! $lonDiffers) {
                $totalSkippedCoordsMatch++;
                $actions[] = 'Coords already match place';
                $tableRows[] = [
                    $toilet->id,
                    $toilet->name ?? "WC #{$toilet->id}",
                    "{$oldLat}, {$oldLon}",
                    "{$newLat}, {$newLon}",
                    $toilet->place_id,
                    implode('; ', $actions),
                ];

                continue;
            }

            // 5. Update coordinates and record revision
            $coordDiff = [];
            if ($latDiffers) {
                $coordDiff['lat'] = ['old' => $oldLat, 'new' => $newLat];
                $toilet->lat = $newLat;
            }
            if ($lonDiffers) {
                $coordDiff['lon'] = ['old' => $oldLon, 'new' => $newLon];
                $toilet->lon = $newLon;
            }

            if ($unflag && $toilet->flagged) {
                $coordDiff['flagged'] = ['old' => true, 'new' => false];
                $toilet->flagged = false;
            }

            $totalCoordinatesUpdated++;
            $actions[] = "Updated coords via {$resolvedCoords['source']}";

            if (! $dryRun) {
                $toilet->last_diff = $coordDiff;
                $toilet->save();

                $this->revisionService->recordRevision(
                    $toilet,
                    'repair_coordinates',
                    $coordDiff,
                    "Updated coordinates from place {$toilet->place_id} (lat: {$newLat}, lon: {$newLon})"
                );
            }

            $tableRows[] = [
                $toilet->id,
                $toilet->name ?? "WC #{$toilet->id}",
                "{$oldLat}, {$oldLon}",
                "{$newLat}, {$newLon}",
                $toilet->place_id,
                implode('; ', $actions),
            ];
        }

        // Render preview table
        $this->table(
            ['ID', 'Name', 'Old Coords', 'New Coords', 'Place ID', 'Action'],
            array_slice($tableRows, 0, 100)
        );

        if (count($tableRows) > 100) {
            $this->line(sprintf('... and %d more records.', count($tableRows) - 100));
        }

        $this->newLine();
        $this->line('====================================================');
        $this->info("Duplicate groups found: {$dupGroupsCount}");
        $this->info("Total toilets processed: {$totalProcessed}");
        $this->info("Place IDs restored from revisions: {$totalPlaceIdsRestored}");
        $this->info("Toilet coordinates updated from place: {$totalCoordinatesUpdated}");
        $this->info("Skipped (already match place): {$totalSkippedCoordsMatch}");
        $this->info("Skipped (user_overridden): {$totalSkippedUserOverridden}");
        $this->info("Place coordinates unavailable: {$totalFailedNoCoords}");
        $this->info("No place_id available: {$totalNoPlaceId}");
        $this->line('====================================================');

        if ($dryRun) {
            $this->warn('DRY-RUN completed. Run without --dry-run to apply changes.');
        } else {
            $this->info('✓ Coordinate repair completed.');
        }

        Log::info('RepairDuplicateCoordinateToiletsCommand completed', [
            'dry_run' => $dryRun,
            'skip_api' => $skipApi,
            'force' => $force,
            'unflag' => $unflag,
            'processed' => $totalProcessed,
            'place_ids_restored' => $totalPlaceIdsRestored,
            'coordinates_updated' => $totalCoordinatesUpdated,
        ]);

        return self::SUCCESS;
    }

    /**
     * Look up previous revisions of a toilet to recover an earlier place_id.
     */
    protected function findEarlierPlaceId(int $toiletId): ?string
    {
        $revisions = DB::table('toilet_revisions')
            ->where('toilet_id', $toiletId)
            ->orderByDesc('version')
            ->get(['version', 'toilet_data', 'diff', 'summary']);

        foreach ($revisions as $rev) {
            // 1. Check toilet_data JSON snapshot
            if (! empty($rev->toilet_data)) {
                $data = is_array($rev->toilet_data) ? $rev->toilet_data : json_decode((string) $rev->toilet_data, true);
                if (! empty($data['place_id']) && is_string($data['place_id'])) {
                    return trim($data['place_id']);
                }
            }

            // 2. Check diff JSON
            if (! empty($rev->diff)) {
                $diff = is_array($rev->diff) ? $rev->diff : json_decode((string) $rev->diff, true);
                if (! empty($diff['place_id']['old']) && is_string($diff['place_id']['old'])) {
                    return trim($diff['place_id']['old']);
                }
                if (! empty($diff['place_id']['new']) && is_string($diff['place_id']['new'])) {
                    return trim($diff['place_id']['new']);
                }
            }

            // 3. Check summary text (e.g. "Place ChIJ... not found in Google Places API; removed place_id and flagged for review")
            if (! empty($rev->summary)) {
                if (preg_match('/Place\s+([A-Za-z0-9_-]+)\s+not found/i', (string) $rev->summary, $matches)) {
                    return trim($matches[1]);
                }
            }
        }

        return null;
    }

    /**
     * Resolve latitude and longitude from cached Place or Google Places API.
     *
     * @param  array<string, array{lat: float, lon: float, source: string}|null>  $placeCache
     * @return array{lat: float, lon: float, source: string}|null
     */
    protected function resolvePlaceCoordinates(string $placeId, bool $skipApi, bool $dryRun, array &$placeCache): ?array
    {
        if (array_key_exists($placeId, $placeCache)) {
            return $placeCache[$placeId];
        }

        // 1. Check local places table
        $place = Place::where('place_id', $placeId)->first();
        if ($place) {
            $lat = $place->getLat();
            $lon = $place->getLon();
            if ($lat !== null && $lon !== null) {
                $result = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'source' => 'cache',
                ];
                $placeCache[$placeId] = $result;

                return $result;
            }
        }

        // 2. Fetch from Google Places API if not skipped
        if (! $skipApi) {
            if (! $this->costService->hasBudget()) {
                if (! $this->budgetWarned) {
                    $this->warn('⚠️ Google API monthly budget reached or exceeded; skipping further external API requests.');
                    $this->budgetWarned = true;
                }

                $placeCache[$placeId] = null;

                return null;
            }

            try {
                $details = $this->placesService->fetchPlaceDetails($placeId);
                if ($details) {
                    if (! $dryRun) {
                        Place::updateOrCreate(
                            ['place_id' => $placeId],
                            ['data' => $details, 'updated' => now()]
                        );
                    }

                    $lat = $details['location']['latitude'] ?? $details['location']['lat'] ?? $details['geometry']['location']['lat'] ?? null;
                    $lon = $details['location']['longitude'] ?? $details['location']['lng'] ?? $details['geometry']['location']['lng'] ?? null;

                    if ($lat !== null && $lon !== null) {
                        $result = [
                            'lat' => round((float) $lat, 7),
                            'lon' => round((float) $lon, 7),
                            'source' => 'google_api',
                        ];
                        $placeCache[$placeId] = $result;

                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("RepairDuplicateCoordinateToilets: Failed to fetch Google Place details for {$placeId}: ".$e->getMessage());
            }
        }

        $placeCache[$placeId] = null;

        return null;
    }
}
