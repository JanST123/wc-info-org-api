<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Toilet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateInitialToiletRevisionsCommand extends Command
{
    protected $signature = 'app:create-initial-toilet-revisions
                            {--dry-run : Preview how many toilets would receive an initial revision without writing anything}
                            {--chunk-size=500 : Number of toilets to process per chunk}
                            {--source=initial : Source label for the created revisions}';

    protected $description = 'Create an initial snapshot revision for all toilets that currently do not have any revisions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(10, (int) $this->option('chunk-size'));
        $source = (string) ($this->option('source') ?: 'initial');

        if ($dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        } else {
            $this->info('Finding toilets without existing revisions...');
        }

        $totalToilets = Toilet::count();
        $missingQuery = Toilet::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('toilet_revisions')
                    ->whereColumn('toilet_revisions.toilet_id', 'toilets.id');
            })
            ->orderBy('id');

        $missingCount = $missingQuery->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total toilets in database', $totalToilets],
                ['Toilets with existing revisions', $totalToilets - $missingCount],
                [$dryRun ? 'Toilets needing initial revision' : 'Toilets to process', $missingCount],
            ]
        );

        if ($missingCount === 0) {
            $this->info('All toilets already have at least one revision.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry run complete. {$missingCount} initial revisions would be created.");

            return self::SUCCESS;
        }

        $this->info("Creating initial revisions in chunks of {$chunkSize}...");
        $bar = $this->output->createProgressBar($missingCount);
        $bar->start();

        $processedCount = 0;
        $now = now();

        $missingQuery->chunkById($chunkSize, function ($toilets) use (&$processedCount, $source, $now, $bar): void {
            $toiletIds = $toilets->pluck('id')->all();

            $propsGrouped = DB::table('toilet_properties')
                ->whereIn('fk_toiletId', $toiletIds)
                ->get()
                ->groupBy('fk_toiletId');

            $revisionInserts = [];
            $versionUpdates = [];

            foreach ($toilets as $toilet) {
                $toiletProps = ($propsGrouped->get($toilet->id) ?? collect())
                    ->pluck('value', 'type')
                    ->toArray();

                $version = max(1, (int) ($toilet->version ?: 1));

                $toiletData = [
                    'name' => $toilet->name,
                    'owner' => $toilet->owner,
                    'lat' => $toilet->lat !== null ? (float) $toilet->lat : null,
                    'lon' => $toilet->lon !== null ? (float) $toilet->lon : null,
                    'place_id' => $toilet->place_id,
                    'status' => $toilet->status,
                    'is_qualified' => (bool) $toilet->is_qualified,
                    'flagged' => (bool) $toilet->flagged,
                    'contact_email' => $toilet->contact_email,
                    'source' => $toilet->source,
                    'user_overridden' => $toilet->user_overridden,
                ];

                $createdAt = $toilet->created_at ? $toilet->created_at->format('Y-m-d H:i:s') : $now->format('Y-m-d H:i:s');

                $revisionInserts[] = [
                    'toilet_id' => $toilet->id,
                    'version' => $version,
                    'source' => $source,
                    'toilet_data' => json_encode($toiletData),
                    'properties_data' => json_encode($toiletProps),
                    'diff' => null,
                    'summary' => 'Initial snapshot',
                    'created_at' => $createdAt,
                ];

                if ((int) $toilet->version !== $version) {
                    $versionUpdates[] = $toilet->id;
                }

                $processedCount++;
            }

            if (! empty($revisionInserts)) {
                DB::table('toilet_revisions')->insert($revisionInserts);
            }

            if (! empty($versionUpdates)) {
                DB::table('toilets')->whereIn('id', $versionUpdates)->update(['version' => 1]);
            }

            $bar->advance(count($toilets));
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Successfully created {$processedCount} initial revisions.");

        return self::SUCCESS;
    }
}
