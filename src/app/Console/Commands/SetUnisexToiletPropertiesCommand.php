<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Toilet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetUnisexToiletPropertiesCommand extends Command
{
    protected $signature = 'app:set-unisex-toilet-properties
                            {--dry-run : Preview changes without modifying database}
                            {--chunk-size=500 : Number of toilets to process per chunk}';

    protected $description = 'Set the is_unisex toilet property to "1" for all toilets that do not have the is_gender_separated property set to 1 and unflag toilets whose last change was setting is_unisex from 0 to 1';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(10, (int) $this->option('chunk-size'));

        if ($dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        } else {
            $this->info('Finding toilets to set is_unisex property...');
        }

        $totalToilets = Toilet::count();

        $genderSeparatedCount = DB::table('toilet_properties')
            ->where('type', 'is_gender_separated')
            ->where('value', '1')
            ->count();

        $alreadyUnisexCount = DB::table('toilet_properties')
            ->where('type', 'is_unisex')
            ->where('value', '1')
            ->count();

        $needingUpdateQuery = Toilet::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('toilet_properties')
                    ->whereColumn('toilet_properties.fk_toiletId', 'toilets.id')
                    ->where('toilet_properties.type', 'is_gender_separated')
                    ->where('toilet_properties.value', '1');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('toilet_properties')
                    ->whereColumn('toilet_properties.fk_toiletId', 'toilets.id')
                    ->where('toilet_properties.type', 'is_unisex')
                    ->where('toilet_properties.value', '1');
            })
            ->orderBy('id');

        $needingCount = $needingUpdateQuery->count();

        // Check flagged toilets whose last change was setting is_unisex from 0 to 1
        $flaggedToilets = Toilet::where('flagged', 1)
            ->with(['latestRevision'])
            ->get();

        $flaggedToUnflag = [];
        foreach ($flaggedToilets as $toilet) {
            $diff = $this->resolveDiff($toilet);
            if ($this->isLastChangeSettingUnisex($diff)) {
                $flaggedToUnflag[] = $toilet->id;
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total toilets in database', $totalToilets],
                ['Gender separated toilets (skipped)', $genderSeparatedCount],
                ['Already set to unisex', $alreadyUnisexCount],
                [$dryRun ? 'Toilets to update is_unisex (dry-run)' : 'Toilets to update is_unisex', $needingCount],
                ['Currently flagged toilets', $flaggedToilets->count()],
                [$dryRun ? 'Flagged toilets to unflag (dry-run)' : 'Flagged toilets to unflag', count($flaggedToUnflag)],
            ]
        );

        if ($dryRun) {
            $this->info("Dry run complete. {$needingCount} toilets would have is_unisex set to 1, and ".count($flaggedToUnflag).' flagged toilets would be unflagged.');

            return self::SUCCESS;
        }

        // 1. Update is_unisex property
        if ($needingCount > 0) {
            $this->info("Setting is_unisex property to 1 in chunks of {$chunkSize}...");
            $bar = $this->output->createProgressBar($needingCount);
            $bar->start();

            $processedCount = 0;

            $needingUpdateQuery->chunkById($chunkSize, function ($toilets) use (&$processedCount, $bar): void {
                $rows = [];
                foreach ($toilets as $toilet) {
                    $rows[] = [
                        'fk_toiletId' => $toilet->id,
                        'type' => 'is_unisex',
                        'value' => '1',
                    ];
                }

                DB::table('toilet_properties')->upsert($rows, ['fk_toiletId', 'type'], ['value']);
                $processedCount += count($rows);
                $bar->advance(count($rows));
            });

            $bar->finish();
            $this->newLine();
            $this->info("Successfully set is_unisex property to 1 for {$processedCount} toilets.");
        } else {
            $this->info('All qualifying toilets already have the is_unisex property set to 1.');
        }

        // 2. Unflag flagged toilets whose last change was setting is_unisex from 0 to 1
        if (count($flaggedToUnflag) > 0) {
            Toilet::whereIn('id', $flaggedToUnflag)->update(['flagged' => false]);
            $this->info('Successfully unflagged '.count($flaggedToUnflag).' toilets where last change was unisex 0 -> 1.');
        } else {
            $this->info('No flagged toilets found where the last change was setting is_unisex from 0 to 1.');
        }

        return self::SUCCESS;
    }

    private function resolveDiff(Toilet $toilet): array
    {
        $diff = $toilet->last_diff;
        if (is_string($diff)) {
            $diff = json_decode($diff, true);
        }
        if (empty($diff) && $toilet->latestRevision && ! empty($toilet->latestRevision->diff)) {
            $diff = $toilet->latestRevision->diff;
        }

        return is_array($diff) ? $diff : [];
    }

    public function isLastChangeSettingUnisex(array $diff): bool
    {
        if (empty($diff) || ! isset($diff['is_unisex'])) {
            return false;
        }

        $unisexChange = $diff['is_unisex'];
        if (! is_array($unisexChange)) {
            return false;
        }

        $oldVal = $unisexChange['old'] ?? null;
        $newVal = $unisexChange['new'] ?? null;

        $isOldZeroOrNull = $oldVal === null || $oldVal === false || $oldVal === 0 || in_array((string) $oldVal, ['0', '', 'false'], true);
        $isNewOne = $newVal === true || $newVal === 1 || in_array((string) $newVal, ['1', 'true'], true);

        if (! $isOldZeroOrNull || ! $isNewOne) {
            return false;
        }

        // Check that all other entries in $diff are either metadata or no-op
        foreach ($diff as $key => $change) {
            if ($key === 'is_unisex' || $key === 'flagged' || $key === 'email_sent') {
                continue;
            }

            if (is_array($change)) {
                $cOld = $change['old'] ?? null;
                $cNew = $change['new'] ?? null;
                if ($cOld === $cNew || (string) $cOld === (string) $cNew) {
                    continue; // No-op change
                }
            }

            // Found another substantive change (e.g. address, website, opening hours, etc.)
            return false;
        }

        return true;
    }
}
