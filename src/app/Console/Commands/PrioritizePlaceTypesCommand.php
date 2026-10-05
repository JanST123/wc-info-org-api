<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PrioritizePlaceTypesCommand extends Command
{
    protected $signature = 'app:prioritize-place-types
                            {--dry-run : Preview changes without writing anything to the database}';

    protected $aliases = [
        'app:priorize-place-types',
    ];

    protected $description = 'Set the priorize flag for place types likely to contain an easily accessible toilet';

    public function handle(): int
    {
        exit(); // do not run this command, it is only for reference and should not be executed in production
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        }

        // Ensure table exists
        if (! Schema::hasTable('types')) {
            $this->error('The "types" table does not exist.');

            return self::FAILURE;
        }

        // Ensure column exists
        if (! Schema::hasColumn('types', 'priorize')) {
            if ($dryRun) {
                $this->warn('Column "priorize" does not exist in table "types" yet (would be created on migration).');
            } else {
                $this->info('Adding "priorize" column to "types" table...');
                DB::statement('ALTER TABLE `types` ADD COLUMN `priorize` TINYINT(1) NOT NULL DEFAULT 0');
            }
        }

        $prioritizedTypes = (array) config('wcinfo.prioritized_place_types', []);

        if (empty($prioritizedTypes)) {
            $this->error('No prioritized_place_types configured in config/wcinfo.php.');

            return self::FAILURE;
        }

        // Normalize configured types for case-insensitive / trimmed matching
        $prioritizedTypesMap = array_fill_keys(array_map('trim', $prioritizedTypes), true);

        // Fetch all rows from types table
        $hasColumn = Schema::hasColumn('types', 'priorize');
        $allTypes = DB::table('types')->get();

        $totalRows = $allTypes->count();
        $matchingRows = 0;
        $nonMatchingRows = 0;
        $toUpdateToOne = 0;
        $toUpdateToZero = 0;
        $alreadyOne = 0;
        $alreadyZero = 0;

        $prioritizedSample = [];
        $nonPrioritizedSample = [];

        foreach ($allTypes as $row) {
            $typeName = (string) $row->type;
            $currentPriorize = $hasColumn ? (int) ($row->priorize ?? 0) : 0;
            $shouldPriorize = isset($prioritizedTypesMap[$typeName]);

            if ($shouldPriorize) {
                $matchingRows++;
                $prioritizedSample[$typeName] = true;
                if ($currentPriorize === 1) {
                    $alreadyOne++;
                } else {
                    $toUpdateToOne++;
                }
            } else {
                $nonMatchingRows++;
                $nonPrioritizedSample[$typeName] = true;
                if ($currentPriorize === 0) {
                    $alreadyZero++;
                } else {
                    $toUpdateToZero++;
                }
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total rows in types table', $totalRows],
                ['Unique type strings matched (priorize = 1)', count($prioritizedSample)],
                ['Unique type strings non-matched (priorize = 0)', count($nonPrioritizedSample)],
                ['Rows already priorize = 1', $alreadyOne],
                ['Rows already priorize = 0', $alreadyZero],
                [$dryRun ? 'Rows to update (0 -> 1)' : 'Rows updated (0 -> 1)', $toUpdateToOne],
                [$dryRun ? 'Rows to update (1 -> 0)' : 'Rows updated (1 -> 0)', $toUpdateToZero],
            ]
        );

        if (! $dryRun) {
            // Apply updates
            $updatedToOne = DB::table('types')
                ->whereIn('type', array_keys($prioritizedTypesMap))
                ->update(['priorize' => 1]);

            $updatedToZero = DB::table('types')
                ->whereNotIn('type', array_keys($prioritizedTypesMap))
                ->update(['priorize' => 0]);

            $this->info("Successfully updated 'types' table: {$updatedToOne} row(s) set to 1, {$updatedToZero} row(s) set to 0.");
        } else {
            $this->info("Dry-run complete. {$toUpdateToOne} row(s) would be set to 1, {$toUpdateToZero} row(s) would be set to 0.");
        }

        return self::SUCCESS;
    }
}
