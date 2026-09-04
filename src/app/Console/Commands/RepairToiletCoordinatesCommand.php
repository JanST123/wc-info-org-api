<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairToiletCoordinatesCommand extends Command
{
    protected $signature = 'app:repair-toilet-coordinates
                            {--dry-run : Preview what would be changed without writing anything}';

    protected $description = 'Backfill toilet lat/lon from places.data when place_id is present';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN mode: no changes will be made.');
        }

        $rows = DB::table('toilets')
            ->whereNotNull('toilets.place_id')
            ->whereNotNull('places.place_id')
            ->join('places', 'places.place_id', '=', 'toilets.place_id')
            ->select(['toilets.id', 'toilets.place_id', 'toilets.user_overridden', 'places.data'])
            ->get();

        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (!empty($row->user_overridden)) {
                $userOverridden = json_decode($row->user_overridden, true);
                if (isset($userOverridden['lat']) || isset($userOverridden['lon'])) {
                    if ($dryRun) {
                        $this->line("[DRY-RUN] id={$row->id} place_id={$row->place_id} -> skipped due to user_overridden lat/lon");
                    }
                    $skipped++;
                    continue;
                }
            }
            $data = json_decode($row->data, true);
            $location = $data['geometry']['location'] ?? $data['result']['geometry']['location'] ?? null;

            if (! $location || ! isset($location['lat'], $location['lng'])) {
                $skipped++;
                continue;
            }

            $lat = (float) $location['lat'];
            $lon = (float) $location['lng'];

            if ($dryRun) {
                $this->line("[DRY-RUN] id={$row->id} place_id={$row->place_id} -> lat={$lat} lon={$lon}");
                $updated++;

                continue;
            }

            DB::table('toilets')->where('id', $row->id)->update([
                'lat' => $lat,
                'lon' => $lon,
            ]);

            $updated++;
        }

        $this->info("Processed {$rows->count()} toilets. Updated: {$updated}, skipped: {$skipped}.");
        Log::info('RepairToiletCoordinates completed', ['dry_run' => $dryRun, 'processed' => $rows->count(), 'updated' => $updated, 'skipped' => $skipped]);

        return self::SUCCESS;
    }
}
