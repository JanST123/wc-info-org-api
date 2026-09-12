<?php

namespace App\Console\Commands;

use App\Services\PlaceToiletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateV2SchemaCommand extends Command
{
    protected $signature = 'app:migrate-v2-schema
                            {--dry-run : Preview what would be changed without writing anything}
                            {--skip-backup-check : Skip the backup confirmation prompt}';

    protected $description = 'Migrate the legacy database schema to the v2 structure';

    private bool $dryRun;

    public function handle(): int
    {
        $this->dryRun = $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        } else {
            $this->warn('This command will modify the database schema and data.');

            if (! $this->option('skip-backup-check') && ! $this->confirm('Did you create a backup?')) {
                $this->error('Aborted. Please create a backup first.');

                return self::FAILURE;
            }
        }

        try {
            $this->preview();

            if ($this->dryRun) {
                $this->info('Dry run preview completed. No changes were made.');

                return self::SUCCESS;
            }

            $this->migrate();
            $this->info('Migration completed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Migration failed: '.$e->getMessage());
            Log::error('MigrateV2Schema failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return self::FAILURE;
        }
    }

    private function preview(): void
    {
        $this->info('Planned schema changes:');
        $this->line('  - RENAME TABLE places_cache TO places');
        $this->line('  - RENAME TABLE place_searchresults TO _archive_place_searchresults');
        $this->line('  - ALTER TABLE toilets: REMOVE PARTITIONING, ADD status, ADD is_qualified, ADD last_included, ADD last_discovered, ADD user_overridden, MODIFY lat/lon DECIMAL');
        $this->line('  - ALTER TABLE toilets: DROP INDEX id_2');
        $this->line('  - ALTER TABLE toilets: DROP COLUMN is_quailified, type, nr');
        $this->line('  - ALTER TABLE toilet_properties: ADD user_overridden, MODIFY type enum with new flags and place_opening_hours');
        $this->line('  - ALTER TABLE unknown_places: MODIFY lat/lon DECIMAL');
        $this->newLine();

        $hasTypeColumn = $this->columnExists('toilets', 'type');
        $hasStatusColumn = $this->columnExists('toilets', 'status');

        if ($hasTypeColumn) {
            $toilets = DB::table('toilets')->select(['id', 'type', 'lat', 'lon', 'is_quailified'])->get();
        } elseif ($hasStatusColumn) {
            $toilets = DB::table('toilets')->select(['id', 'status', 'lat', 'lon', 'is_qualified'])->get();
        } else {
            $toilets = collect();
        }

        $statuses = ['active' => 0, 'hidden' => 0, 'deleted' => 0];
        $flagCount = 0;

        foreach ($toilets as $row) {
            if ($hasTypeColumn) {
                $status = $row->type === 'none' || $row->type === '' || $row->type === null ? 'hidden' : 'active';

                $type = (string) $row->type;
                if ($type === 'forall' || str_contains($type, 'u')) {
                    $flagCount++;
                }
                if (str_contains($type, 'mw')) {
                    $flagCount++;
                }
                if (str_contains($type, 'd')) {
                    $flagCount++;
                }
                if (str_contains($type, 'b')) {
                    $flagCount++;
                }
            } else {
                $status = $row->status;
            }

            $statuses[$status]++;
        }

        $this->info('Planned data changes:');
        $this->line("  - Toilets updated: {$toilets->count()}");
        $this->line("      active: {$statuses['active']}, hidden: {$statuses['hidden']}, deleted: {$statuses['deleted']}");
        $this->line("  - Flag properties inserted: {$flagCount}");

        $oldProps = DB::table('toilet_properties')
            ->whereIn('type', ['opening_times_json', 'opening_times_json2', 'opening_times_chatgpt_response'])
            ->count();
        $legacyText = DB::table('toilet_properties')->where('type', 'opening_times')->count();

        $euroKeyOnes = DB::table('toilet_properties')->where('type', 'euro_key')->where('value', '1')->count();
        $euroKeyZeros = DB::table('toilet_properties')->where('type', 'euro_key')->where('value', '0')->count();
        $this->line("  - euro_key values converted to 'yes'/'no': {$euroKeyOnes} '1' -> 'yes', {$euroKeyZeros} '0' -> 'no'");

        $migratedHours = 0;
        $placesTable = $this->tableExists('places_cache') ? 'places_cache' : 'places';
        $toiletsWithPlace = DB::table('toilets')->whereNotNull('place_id')->pluck('place_id');
        foreach ($toiletsWithPlace as $placeId) {
            $place = DB::table($placesTable)->where('place_id', $placeId)->first();
            if (! $place) {
                continue;
            }
            $data = json_decode($place->data, true);
            $periods = $data['opening_hours']['periods'] ?? $data['result']['opening_hours']['periods'] ?? null;
            if (is_array($periods) && count($periods) > 0) {
                $migratedHours++;
            }
        }

        $this->line("  - Old machine-generated opening-hours properties deleted: {$oldProps}");
        $this->line("  - Legacy opening_times properties deleted: {$legacyText}");
        $this->line("  - place_opening_hours properties inserted from places_cache.data.periods: {$migratedHours}");

        $unknown = DB::table('unknown_places')->count();
        $this->line("  - unknown_places coordinates converted: {$unknown}");
    }

    private function migrate(): void
    {
        // The legacy schema contains zero/invalid dates. Allow them during the migration
        // so ALTER TABLE copies do not fail under strict SQL mode.
        DB::statement("SET SESSION sql_mode=(SELECT REPLACE(REPLACE(@@sql_mode,'NO_ZERO_DATE',''),'NO_ZERO_IN_DATE',''))");

        $this->renameTables();
        $this->prepareToiletsTable();
        $this->migrateToiletData();
        $this->convertToiletCoordinates();
        $this->migratePropertyTypes();
        $this->migrateTypeToFlags();
        $this->migrateOpeningHours();
        $this->migrateEuroKeyValues();
        $this->cleanupToiletsTable();
        $this->cleanupPropertiesTable();
        $this->cleanupUnknownPlaces();
        $this->createCostTrackingTables();
        $this->createToiletRevisionsTable();
        $this->optimizeIndexes();
    }

    private function renameTables(): void
    {
        $this->info('Renaming tables...');

        $cacheExists = $this->tableExists('places_cache');
        $placesExists = $this->tableExists('places');

        if ($cacheExists && ! $placesExists) {
            $this->runStatement('RENAME TABLE places_cache TO places');
        } elseif (! $cacheExists && $placesExists) {
            $this->info('places table already exists, skipping rename.');
        } else {
            $this->warn('Unexpected table state for places_cache/places.');
        }

        $searchResultsExists = $this->tableExists('place_searchresults');
        if ($searchResultsExists) {
            $this->runStatement('RENAME TABLE place_searchresults TO _archive_place_searchresults');
        } else {
            $this->info('place_searchresults already renamed or missing, skipping.');
        }
    }

    private function prepareToiletsTable(): void
    {
        $this->info('Preparing toilets table...');

        if ($this->isPartitioned('toilets')) {
            $this->runStatement('ALTER TABLE toilets REMOVE PARTITIONING');
        } else {
            $this->info('Table toilets is not partitioned, skipping REMOVE PARTITIONING.');
        }

        if (! $this->columnExists('toilets', 'status')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN status ENUM("active", "hidden", "deleted") NOT NULL DEFAULT "active" AFTER place_id');
        } else {
            $this->info('Column status already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'is_qualified')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN is_qualified TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
        } else {
            $this->info('Column is_qualified already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'flagged')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN flagged TINYINT(1) NOT NULL DEFAULT 0 AFTER is_qualified');
        } else {
            $this->info('Column flagged already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'created_at')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER is_qualified');
        } else {
            $this->info('Column created_at already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'last_included')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN last_included DATETIME DEFAULT NULL AFTER source');
        } else {
            $this->info('Column last_included already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'last_discovered')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN last_discovered DATETIME DEFAULT NULL AFTER last_included');
        } else {
            $this->info('Column last_discovered already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'last_places_fetch')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN last_places_fetch DATETIME DEFAULT NULL AFTER last_discovered');
        } else {
            $this->info('Column last_places_fetch already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'last_crawled')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN last_crawled DATETIME DEFAULT NULL AFTER last_places_fetch');
        } else {
            $this->info('Column last_crawled already exists, skipping.');
        }

        if (! $this->columnExists('toilets', 'user_overridden')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN user_overridden JSON NULL DEFAULT NULL COMMENT "Fields the user has manually overridden" AFTER last_crawled');
        } else {
            $this->info('Column user_overridden already exists, skipping.');
        }

        if ($this->indexExists('toilets', 'id_2')) {
            $this->runStatement('ALTER TABLE toilets DROP INDEX id_2');
        } else {
            $this->info('Index id_2 already dropped, skipping.');
        }
    }

    private function convertToiletCoordinates(): void
    {
        $this->info('Converting toilet lat/lon to decimal degrees...');

        $this->runStatement('
            ALTER TABLE toilets
            MODIFY COLUMN lat DECIMAL(10, 8) DEFAULT NULL COMMENT "WGS84 latitude",
            MODIFY COLUMN lon DECIMAL(11, 8) DEFAULT NULL COMMENT "WGS84 longitude"
        ');
    }

    private function migrateToiletData(): void
    {
        $this->info('Migrating toilet data...');

        if (! $this->columnExists('toilets', 'type')) {
            $this->info('Legacy type column already dropped, skipping toilet data migration.');

            return;
        }

        $rows = DB::table('toilets')->select(['id', 'type', 'lat', 'lon', 'is_quailified'])->get();

        foreach ($rows as $row) {
            $status = $row->type === 'none' || $row->type === '' || $row->type === null ? 'hidden' : 'active';
            $lat = $row->lat !== null ? $row->lat / 10000 : null;
            $lon = $row->lon !== null ? $row->lon / 10000 : null;

            $this->runStatement(
                'UPDATE toilets SET status = ?, is_qualified = ?, lat = ?, lon = ? WHERE id = ?',
                [$status, $row->is_quailified, $lat, $lon, $row->id]
            );
        }

        $this->info("Migrated {$rows->count()} toilets.");
    }

    private function migratePropertyTypes(): void
    {
        $this->info('Updating toilet_properties enum...');

        if (! $this->columnExists('toilet_properties', 'user_overridden')) {
            $this->runStatement('ALTER TABLE toilet_properties ADD COLUMN user_overridden TINYINT(1) NOT NULL DEFAULT 0 AFTER value');
        } else {
            $this->info('Column user_overridden already exists on toilet_properties, skipping.');
        }

        $this->runStatement('
            ALTER TABLE toilet_properties
            MODIFY COLUMN type ENUM(
                "opening_times",
                "address",
                "website",
                "euro_key",
                "comment",
                "place_opening_hours",
                "opening_times_json",
                "opening_times_json2",
                "opening_times_chatgpt_response",
                "is_unisex",
                "is_gender_separated",
                "has_wheelchair_access",
                "has_changing_table",
                "accessible_outside_opening_times",
                "public_accessible",
                "storage_space"
            ) NOT NULL
        ');
    }

    private function migrateTypeToFlags(): void
    {
        $this->info('Migrating type enum to boolean property flags...');

        if (! $this->columnExists('toilets', 'type')) {
            $this->info('Legacy type column already dropped, skipping flag migration.');

            return;
        }

        $rows = DB::table('toilets')->select(['id', 'type'])->get();
        $count = 0;

        foreach ($rows as $row) {
            $type = (string) $row->type;

            $flags = [
                'is_unisex' => $type === 'forall' || str_contains($type, 'u'),
                'is_gender_separated' => str_contains($type, 'mw'),
                'has_wheelchair_access' => str_contains($type, 'd'),
                'has_changing_table' => str_contains($type, 'b'),
            ];

            foreach ($flags as $flag => $value) {
                if ($value) {
                    $this->runStatement(
                        'INSERT INTO toilet_properties (fk_toiletId, type, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = ?',
                        [$row->id, $flag, '1', '1']
                    );
                    $count++;
                }
            }
        }

        $this->info("Inserted {$count} flag properties.");
    }

    private function migrateOpeningHours(): void
    {
        $this->info('Migrating opening hours from places data...');

        $deleted = $this->runStatement('
            DELETE FROM toilet_properties
            WHERE type IN ("opening_times_json", "opening_times_json2", "opening_times_chatgpt_response")
        ');
        $this->info("Deleted old machine-generated opening-hours properties: {$deleted}");

        $rows = DB::table('toilets')->whereNotNull('place_id')->select(['toilets.id', 'toilets.place_id'])->get();
        $migrated = 0;

        foreach ($rows as $row) {
            $place = DB::table('places')->where('place_id', $row->place_id)->first();

            if (! $place) {
                continue;
            }

            $data = json_decode($place->data, true);
            $rawPeriods = $data['regularOpeningHours']['periods']
                ?? $data['opening_hours']['periods']
                ?? $data['result']['opening_hours']['periods']
                ?? null;

            if (! is_array($rawPeriods) || count($rawPeriods) === 0) {
                continue;
            }

            $periods = PlaceToiletService::normalizePeriodPoints($rawPeriods);
            if (count($periods) === 0) {
                continue;
            }

            $value = json_encode($periods);
            $this->runStatement(
                'INSERT INTO toilet_properties (fk_toiletId, type, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = ?',
                [$row->id, 'place_opening_hours', $value, $value]
            );
            $migrated++;
        }

        $deleted = $this->runStatement('DELETE FROM toilet_properties WHERE type = "opening_times"');
        $this->info("Deleted legacy opening_times properties: {$deleted}");
        $this->info("Migrated {$migrated} place_opening_hours properties.");
    }

    private function migrateEuroKeyValues(): void
    {
        $this->info('Normalizing euro_key values...');

        $yesUpdated = $this->runStatement("UPDATE toilet_properties SET value = 'yes' WHERE type = 'euro_key' AND value = '1'");
        $noUpdated = $this->runStatement("UPDATE toilet_properties SET value = 'no' WHERE type = 'euro_key' AND value = '0'");

        $this->info("Converted euro_key: {$yesUpdated} from '1' to 'yes', {$noUpdated} from '0' to 'no'.");
    }

    private function cleanupToiletsTable(): void
    {
        $this->info('Cleaning up toilets table...');

        if ($this->columnExists('toilets', 'is_quailified')) {
            $this->runStatement('ALTER TABLE toilets DROP COLUMN is_quailified');
        } else {
            $this->info('Column is_quailified already dropped, skipping.');
        }

        if ($this->columnExists('toilets', 'type')) {
            $this->runStatement('ALTER TABLE toilets DROP COLUMN type');
        } else {
            $this->info('Column type already dropped, skipping.');
        }

        if ($this->columnExists('toilets', 'nr')) {
            $this->runStatement('ALTER TABLE toilets DROP COLUMN nr');
        } else {
            $this->info('Column nr already dropped, skipping.');
        }
    }

    private function cleanupPropertiesTable(): void
    {
        $this->info('Cleaning up toilet_properties enum...');

        $this->runStatement('
            ALTER TABLE toilet_properties
            MODIFY COLUMN type ENUM(
                "address",
                "website",
                "euro_key",
                "comment",
                "place_opening_hours",
                "is_unisex",
                "is_gender_separated",
                "has_wheelchair_access",
                "has_changing_table",
                "accessible_outside_opening_times",
                "public_accessible",
                "storage_space"
            ) NOT NULL
        ');
    }

    private function cleanupUnknownPlaces(): void
    {
        $this->info('Cleaning up unknown_places coordinates...');

        $isAlreadyDecimal = $this->columnType('unknown_places', 'lat') === 'decimal(10,8)';

        if (! $isAlreadyDecimal) {
            $count = 0;
            DB::table('unknown_places')
                ->orderBy('place_id')
                ->chunk(1000, function ($rows) use (&$count) {
                    foreach ($rows as $row) {
                        $this->runStatement(
                            'UPDATE unknown_places SET lat = ?, lon = ? WHERE place_id = ?',
                            [$row->lat / 10000, $row->lon / 10000, $row->place_id]
                        );
                        $count++;
                    }
                });

            $this->runStatement('
                ALTER TABLE unknown_places
                MODIFY COLUMN lat DECIMAL(10, 8) NOT NULL COMMENT "WGS84 latitude",
                MODIFY COLUMN lon DECIMAL(11, 8) NOT NULL COMMENT "WGS84 longitude"
            ');

            $this->info('Migrated '.$count.' unknown_places coordinates.');
        } else {
            $this->info('unknown_places coordinates already decimal, skipping.');
        }
    }

    private function createCostTrackingTables(): void
    {
        $this->info('Creating cost tracking and settings tables...');

        if (! $this->tableExists('google_api_logs')) {
            $this->runStatement("
                CREATE TABLE google_api_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    service VARCHAR(50) NOT NULL,
                    endpoint VARCHAR(255) NOT NULL,
                    cost_usd DECIMAL(8, 4) NOT NULL DEFAULT 0.0000,
                    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                    context JSON NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_service_created (service, created_at),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            $this->runStatement('ALTER TABLE google_api_logs MODIFY COLUMN service VARCHAR(50) NOT NULL');
            $this->info('Table google_api_logs updated service column to VARCHAR(50).');
        }

        if (! $this->tableExists('app_settings')) {
            $this->runStatement("
                CREATE TABLE app_settings (
                    `key` VARCHAR(100) PRIMARY KEY,
                    `value` LONGTEXT NULL DEFAULT NULL,
                    `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            $this->info('Table app_settings already exists, skipping.');
        }
    }

    private function createToiletRevisionsTable(): void
    {
        $this->info('Creating toilet_revisions table and version column...');

        if (! $this->columnExists('toilets', 'version')) {
            $this->runStatement('ALTER TABLE toilets ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_qualified');
        } else {
            $this->info('Column version already exists on toilets, skipping.');
        }

        if (! $this->tableExists('toilet_revisions')) {
            $this->runStatement("
                CREATE TABLE toilet_revisions (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    toilet_id INT UNSIGNED NOT NULL,
                    version INT UNSIGNED NOT NULL,
                    source VARCHAR(50) NOT NULL COMMENT 'e.g. initial, admin_edit, api_patch, add_properties, batch_place_assign, restore',
                    toilet_data JSON NOT NULL COMMENT 'Snapshot of toilet model attributes',
                    properties_data JSON NOT NULL COMMENT 'Snapshot of toilet properties key-value map',
                    diff JSON NULL COMMENT 'Diff of changes compared to previous version',
                    summary VARCHAR(255) NULL COMMENT 'Human readable summary of changes',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_toilet_version (toilet_id, version),
                    INDEX idx_toilet_created (toilet_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            $this->info('Table toilet_revisions already exists, skipping.');
        }
    }

    private function optimizeIndexes(): void
    {
        $this->info('Optimizing database indexes...');

        // 1. toilets (status, lat, lon) composite index for bounding box and nearby queries
        if (! $this->indexExists('toilets', 'idx_toilets_status_coords')) {
            $this->runStatement('CREATE INDEX idx_toilets_status_coords ON toilets (status, lat, lon)');
        } else {
            $this->info('Index idx_toilets_status_coords already exists, skipping.');
        }

        // 2. toilets (flagged, id) index for admin flagged review list
        if (! $this->indexExists('toilets', 'idx_toilets_flagged')) {
            $this->runStatement('CREATE INDEX idx_toilets_flagged ON toilets (flagged, id)');
        } else {
            $this->info('Index idx_toilets_flagged already exists, skipping.');
        }

        // 3. toilets (created_at) index for admin dashboard 24h list
        if (! $this->indexExists('toilets', 'idx_toilets_created_at')) {
            $this->runStatement('CREATE INDEX idx_toilets_created_at ON toilets (created_at)');
        } else {
            $this->info('Index idx_toilets_created_at already exists, skipping.');
        }

        // 4. Drop redundant fk_toiletId index on toilet_properties if present (already prefix of PRIMARY KEY)
        if ($this->indexExists('toilet_properties', 'fk_toiletId')) {
            $this->runStatement('ALTER TABLE toilet_properties DROP INDEX fk_toiletId');
        } else {
            $this->info('Redundant index fk_toiletId already removed, skipping.');
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    private function columnType(string $table, string $column): string
    {
        $result = DB::select('SHOW COLUMNS FROM `'.$table.'` WHERE Field = ?', [$column]);

        return ! empty($result) ? strtolower($result[0]->Type) : '';
    }

    private function isPartitioned(string $table): bool
    {
        $result = DB::select('
            SELECT COUNT(*) AS partitioned
            FROM information_schema.partitions
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND partition_name IS NOT NULL
        ', [$table]);

        return ! empty($result) && ($result[0]->partitioned ?? 0) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);

        return ! empty($result);
    }

    private function runStatement(string $sql, array $bindings = []): int
    {
        $affected = DB::affectingStatement($sql, $bindings);

        if (count($bindings) > 100) {
            Log::debug('MigrateV2Schema executed bulk statement', ['sql' => substr($sql, 0, 200)]);
        } else {
            Log::debug('MigrateV2Schema executed statement', ['sql' => $sql, 'bindings' => $bindings, 'affected' => $affected]);
        }

        return $affected;
    }
}
