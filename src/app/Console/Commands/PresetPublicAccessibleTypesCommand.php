<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Toilet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PresetPublicAccessibleTypesCommand extends Command
{
    protected $signature = 'app:preset-public-accessible-types
                            {--dry-run : Preview changes without writing anything}';

    protected $description = 'Preset the public_accessible flag for toilets based on Google Places types';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        }

        $publicTypes = (array) config('wcinfo.public_accessible_types', []);

        if (empty($publicTypes)) {
            $this->error('No public_accessible_types configured in config/wcinfo.php.');

            return self::FAILURE;
        }

        $this->info('Configured public place types: '.count($publicTypes));

        // Find all place_ids matching public types
        $matchingPlaceIds = DB::table('type_x_place')
            ->join('types', 'type_x_place.type_id', '=', 'types.id')
            ->whereIn('types.type', $publicTypes)
            ->distinct()
            ->pluck('type_x_place.place_id')
            ->all();

        $this->info('Places with public types found in database: '.count($matchingPlaceIds));

        if (empty($matchingPlaceIds)) {
            $this->info('No matching places found.');

            return self::SUCCESS;
        }

        $toilets = Toilet::whereNotNull('place_id')
            ->whereIn('place_id', $matchingPlaceIds)
            ->with(['properties'])
            ->get();

        $totalMatching = $toilets->count();
        $alreadySet = 0;
        $userOverridden = 0;
        $toUpdate = [];

        foreach ($toilets as $toilet) {
            $property = $toilet->properties->firstWhere('type', 'public_accessible');

            if ($property && $property->user_overridden) {
                $userOverridden++;

                continue;
            }

            if ($property && $property->value === '1') {
                $alreadySet++;

                continue;
            }

            $toUpdate[] = $toilet->id;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total toilets matching public types', $totalMatching],
                ['Already set to public accessible', $alreadySet],
                ['User overridden (skipped)', $userOverridden],
                [$dryRun ? 'Toilets to update' : 'Toilets updated', count($toUpdate)],
            ]
        );

        if (! $dryRun && count($toUpdate) > 0) {
            $chunks = array_chunk($toUpdate, 500);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $toiletId) {
                    DB::table('toilet_properties')->updateOrInsert(
                        ['fk_toiletId' => $toiletId, 'type' => 'public_accessible'],
                        ['value' => '1', 'user_overridden' => 0]
                    );
                }
            }

            $this->info('Successfully preset public_accessible for '.count($toUpdate).' toilets.');
        } elseif ($dryRun && count($toUpdate) > 0) {
            $this->info('Dry-run complete. '.count($toUpdate).' toilets would be updated.');
        } else {
            $this->info('All matching toilets are already up to date.');
        }

        return self::SUCCESS;
    }
}
