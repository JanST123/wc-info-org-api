<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Services\ToiletRevisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetectDuplicateToiletsCommand extends Command
{
    protected $signature = 'app:detect-duplicate-toilets
                            {--distance=10 : Proximity threshold in meters (default: 10)}
                            {--dry-run : Preview duplicate toilets and total counts without writing changes}
                            {--no-relink-photos : Do not move uploaded photos from duplicate toilets to the master toilet}
                            {--place-id-only : Only detect duplicates by identical Google place_id}
                            {--coords-only : Only detect duplicates by coordinate proximity}';

    protected $description = 'Detect duplicate toilets by place_id or coordinate proximity (<=10m) and mark duplicates as deleted and unflagged';

    public function __construct(
        protected ToiletRevisionService $revisionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $distanceThreshold = (float) ($this->option('distance') ?: 10.0);
        $dryRun = (bool) $this->option('dry-run');
        $relinkPhotos = ! (bool) $this->option('no-relink-photos');
        $placeIdOnly = (bool) $this->option('place-id-only');
        $coordsOnly = (bool) $this->option('coords-only');

        if ($dryRun) {
            $this->warn('🔍 DRY-RUN MODE: No changes will be written to the database.');
        }

        $this->info("Scanning active and hidden toilets (distance threshold: {$distanceThreshold}m)...");

        // Fetch lightweight toilet records for fast clustering
        $toilets = DB::table('toilets')
            ->where('status', '!=', 'deleted')
            ->select(['id', 'name', 'place_id', 'lat', 'lon', 'status', 'is_qualified', 'user_overridden'])
            ->get();

        $totalActiveToilets = $toilets->count();
        $this->line("Found {$totalActiveToilets} active/hidden toilets in database.");

        if ($totalActiveToilets <= 1) {
            $this->info('Not enough toilets to detect duplicates.');
            return self::SUCCESS;
        }

        // Initialize Disjoint-Set / Union-Find for clustering
        $parent = [];
        foreach ($toilets as $t) {
            $parent[$t->id] = $t->id;
        }

        $find = function (int $i) use (&$parent, &$find): int {
            if ($parent[$i] === $i) {
                return $i;
            }
            return $parent[$i] = $find($parent[$i]);
        };

        $union = function (int $i, int $j) use (&$parent, $find): void {
            $rootI = $find($i);
            $rootJ = $find($j);
            if ($rootI !== $rootJ) {
                $parent[$rootI] = $rootJ;
            }
        };

        // 1. Group by identical place_id (unless coords-only)
        $placeMatchesCount = 0;
        if (! $coordsOnly) {
            $placeGroups = [];
            foreach ($toilets as $t) {
                if (! empty($t->place_id)) {
                    $placeGroups[$t->place_id][] = $t->id;
                }
            }

            foreach ($placeGroups as $placeId => $toiletIds) {
                if (count($toiletIds) > 1) {
                    $placeMatchesCount += (count($toiletIds) - 1);
                    for ($i = 1; $i < count($toiletIds); $i++) {
                        $union($toiletIds[0], $toiletIds[$i]);
                    }
                }
            }
        }

        // 2. Group by coordinate proximity (unless place-id-only)
        $coordMatchesCount = 0;
        if (! $placeIdOnly) {
            $cellSize = 0.0001; // ~11m spatial grid bucket
            $grid = [];

            foreach ($toilets as $t) {
                if ($t->lat === null || $t->lon === null || $t->lat === '' || $t->lon === '') {
                    continue;
                }

                $lat = (float) $t->lat;
                $lon = (float) $t->lon;

                // Safeguard: Exclude coarse / integer-truncated coordinates (e.g. 51.0, 7.0)
                // where hundreds of distinct regional toilets were imported to a single integer coordinate
                if (abs($lat - round($lat, 2)) < 0.0001 && abs($lon - round($lon, 2)) < 0.0001) {
                    continue;
                }

                $gx = (int) floor($lat / $cellSize);
                $gy = (int) floor($lon / $cellSize);
                $grid["{$gx}:{$gy}"][] = $t;
            }

            $seenPairs = [];
            foreach ($grid as $key => $cellToilets) {
                [$gx, $gy] = explode(':', $key);
                $gx = (int) $gx;
                $gy = (int) $gy;

                // Inspect 3x3 neighboring grid cells
                for ($dx = -1; $dx <= 1; $dx++) {
                    for ($dy = -1; $dy <= 1; $dy++) {
                        $nKey = ($gx + $dx) . ':' . ($gy + $dy);
                        if (! isset($grid[$nKey])) {
                            continue;
                        }

                        foreach ($cellToilets as $t1) {
                            foreach ($grid[$nKey] as $t2) {
                                if ($t1->id >= $t2->id) {
                                    continue;
                                }

                                $pairKey = $t1->id . '-' . $t2->id;
                                if (isset($seenPairs[$pairKey])) {
                                    continue;
                                }
                                $seenPairs[$pairKey] = true;

                                $dist = $this->calculateDistanceMeters(
                                    (float) $t1->lat,
                                    (float) $t1->lon,
                                    (float) $t2->lat,
                                    (float) $t2->lon
                                );

                                if ($dist <= $distanceThreshold) {
                                    $coordMatchesCount++;
                                    $union($t1->id, $t2->id);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Assemble clusters from Disjoint Set
        $clusters = [];
        foreach ($toilets as $t) {
            $root = $find($t->id);
            $clusters[$root][] = $t->id;
        }

        // Filter only duplicate clusters (size > 1)
        $duplicateClusters = array_values(array_filter($clusters, fn ($c) => count($c) > 1));

        if (empty($duplicateClusters)) {
            $this->info('✓ No duplicate toilets found.');
            return self::SUCCESS;
        }

        // Eager load full Eloquent models ONLY for toilets in duplicate clusters
        $allClusterIds = [];
        foreach ($duplicateClusters as $c) {
            foreach ($c as $tid) {
                $allClusterIds[] = $tid;
            }
        }

        $clusterModels = Toilet::with(['properties', 'photos'])
            ->whereIn('id', $allClusterIds)
            ->get()
            ->keyBy('id');

        $this->line('');
        $this->info(sprintf(
            'Found %d duplicate cluster(s) containing duplicates.',
            count($duplicateClusters)
        ));
        $this->line('');

        $totalDeleted = 0;
        $totalPhotosMoved = 0;
        $tableRows = [];

        foreach ($duplicateClusters as $index => $clusterIds) {
            $cluster = array_values(array_filter(array_map(fn ($id) => $clusterModels->get($id), $clusterIds)));
            if (count($cluster) <= 1) {
                continue;
            }

            // Sort cluster toilets by score descending, then lowest ID first
            usort($cluster, function (Toilet $a, Toilet $b) {
                $scoreA = $this->calculateToiletScore($a);
                $scoreB = $this->calculateToiletScore($b);
                if ($scoreA !== $scoreB) {
                    return $scoreB <=> $scoreA;
                }
                return $a->id <=> $b->id;
            });

            /** @var Toilet $master */
            $master = $cluster[0];
            $duplicates = array_slice($cluster, 1);

            $masterPlace = $master->place_id ? substr($master->place_id, 0, 14) . '...' : 'none';
            $masterInfo = sprintf(
                '#%d "%s" [%s%s, 📷%d, %s]',
                $master->id,
                $master->name ?: 'Unnamed',
                $master->status,
                $master->is_qualified ? ', qual' : '',
                $master->photos->count(),
                $masterPlace
            );

            foreach ($duplicates as $dup) {
                $totalDeleted++;

                $distToMaster = ($master->lat !== null && $master->lon !== null && $dup->lat !== null && $dup->lon !== null)
                    ? round($this->calculateDistanceMeters((float) $master->lat, (float) $master->lon, (float) $dup->lat, (float) $dup->lon), 1) . 'm'
                    : 'N/A';

                $dupPlace = $dup->place_id ? substr($dup->place_id, 0, 14) . '...' : 'none';
                $dupInfo = sprintf(
                    '#%d "%s" [%s%s, 📷%d, %s, dist: %s]',
                    $dup->id,
                    $dup->name ?: 'Unnamed',
                    $dup->status,
                    $dup->is_qualified ? ', qual' : '',
                    $dup->photos->count(),
                    $dupPlace,
                    $distToMaster
                );

                $reason = ($master->place_id && $dup->place_id && $master->place_id === $dup->place_id)
                    ? 'Same place_id'
                    : "Proximity ({$distToMaster})";

                $tableRows[] = [
                    'Cluster' => $index + 1,
                    'Action' => 'DELETE',
                    'Toilet ID' => $dup->id,
                    'Details' => $dupInfo,
                    'Master Toilet' => "#{$master->id} (" . ($master->name ?: 'Unnamed') . ')',
                    'Reason' => $reason,
                ];

                if (! $dryRun) {
                    DB::transaction(function () use ($master, $dup, $relinkPhotos, &$totalPhotosMoved) {
                        // 1. Re-link photos to master toilet if requested
                        if ($relinkPhotos && $dup->photos->count() > 0) {
                            $moved = ToiletPhoto::where('fk_toiletId', $dup->id)
                                ->whereNull('deleted_ts')
                                ->update(['fk_toiletId' => $master->id]);
                            $totalPhotosMoved += $moved;
                        }

                        // 2. Mark duplicate toilet as deleted and unflagged
                        $oldStatus = $dup->status;
                        $oldFlagged = (bool) $dup->flagged;

                        $diff = [
                            'status' => ['old' => $oldStatus, 'new' => 'deleted'],
                            'flagged' => ['old' => $oldFlagged, 'new' => false],
                            'duplicate_of' => ['old' => null, 'new' => $master->id],
                        ];

                        $dup->update([
                            'status' => 'deleted',
                            'flagged' => 0,
                            'email_sent' => 2,
                            'last_diff' => json_encode($diff),
                        ]);

                        // 3. Record revision
                        $this->revisionService->recordRevision(
                            $dup,
                            'admin_deduplication',
                            $diff,
                            "Marked deleted as duplicate of master Toilet #{$master->id}"
                        );
                    });
                }
            }
        }

        // Render preview table
        $this->table(
            ['Cluster', 'Action', 'Toilet ID', 'Details', 'Master Toilet', 'Reason'],
            array_slice($tableRows, 0, 100)
        );

        if (count($tableRows) > 100) {
            $this->line(sprintf('... and %d more duplicate records.', count($tableRows) - 100));
        }

        $this->line('');
        $this->line('====================================================');
        $this->info("Total duplicate clusters: " . count($duplicateClusters));
        $this->info("Total duplicate toilets to mark deleted: {$totalDeleted}");
        if ($relinkPhotos) {
            $this->info("Total photos re-linked to master: {$totalPhotosMoved}");
        }
        $this->line('====================================================');

        if ($dryRun) {
            $this->warn("DRY-RUN completed. Run without --dry-run to apply changes.");
        } else {
            $this->info("✓ Successfully marked {$totalDeleted} duplicate toilets as deleted and unflagged.");
        }

        Log::info('DetectDuplicateToiletsCommand finished', [
            'dry_run' => $dryRun,
            'distance_threshold' => $distanceThreshold,
            'clusters_count' => count($duplicateClusters),
            'deleted_count' => $totalDeleted,
            'photos_moved' => $totalPhotosMoved,
        ]);

        return self::SUCCESS;
    }

    /**
     * Calculate quality score for a toilet record to determine the best Master record.
     */
    private function calculateToiletScore(Toilet $toilet): int
    {
        $score = 0;

        // Qualification is primary
        if ($toilet->is_qualified) {
            $score += 10000;
        }

        // Active status over hidden
        if ($toilet->status === 'active') {
            $score += 5000;
        }

        // Uploaded photos
        $score += ($toilet->photos->count() * 1000);

        // Valid place_id assigned
        if (! empty($toilet->place_id)) {
            $score += 500;
        }

        // User overrides (human edits)
        if (! empty($toilet->user_overridden)) {
            $score += 200;
        }

        // Metadata / properties richness
        $score += ($toilet->properties->count() * 50);

        // Meaningful name
        if (! empty($toilet->name) && ! str_starts_with($toilet->name, 'WC #') && $toilet->name !== 'Toilette') {
            $score += 50;
        }

        return $score;
    }

    /**
     * Calculate Haversine distance in meters between two coordinates.
     */
    private function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(sqrt($a));
    }
}
