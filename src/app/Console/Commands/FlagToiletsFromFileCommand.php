<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FlagToiletsFromFileCommand extends Command
{
    protected $signature = 'app:flag-toilets-from-file
                            {file? : Path to the markdown file containing toilets to check (default: ../toilets_to_check.md)}
                            {--dry-run : Only parse and show count without updating the database}';

    protected $description = 'Parse unchecked toilets from markdown file and set flagged=1 in the database';

    public function handle(): int
    {
        $filePath = $this->argument('file') ?: base_path('../toilets_to_check.md');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        $this->info("Reading and parsing file: {$filePath}...");

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        $flaggedIds = [];
        $checkedIds = [];

        foreach ($lines as $line) {
            if (preg_match('/(?:-\s*\[([ xX])\]|\b([xX]?)\\\\\])?\s*\|\s*#(\d+)\s*\|/', $line, $matches)) {
                $checkMark = strtolower($matches[1] ?: $matches[2] ?: '');
                $toiletId = (int) $matches[3];

                if ($checkMark === 'x') {
                    $checkedIds[$toiletId] = true;
                } else {
                    $flaggedIds[$toiletId] = true;
                }
            }
        }

        // Exclude any IDs that were checked with - [x]
        foreach (array_keys($checkedIds) as $checkedId) {
            unset($flaggedIds[$checkedId]);
        }

        $targetIds = array_keys($flaggedIds);
        sort($targetIds);

        $this->info(sprintf('Parsed %d checked toilets (ignored).', count($checkedIds)));
        $this->info(sprintf('Found %d unique unchecked toilets to flag.', count($targetIds)));

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: No database changes applied.');

            return self::SUCCESS;
        }

        if (empty($targetIds)) {
            $this->info('No toilets to flag.');

            return self::SUCCESS;
        }

        $this->info('Updating toilets table in chunks of 500...');

        $affectedTotal = 0;
        $chunks = array_chunk($targetIds, 500);

        foreach ($chunks as $chunk) {
            $affected = DB::table('toilets')
                ->whereIn('id', $chunk)
                ->update(['flagged' => 1]);
            $affectedTotal += $affected;
        }

        $this->info("Successfully updated {$affectedTotal} toilets with flagged=1.");

        return self::SUCCESS;
    }
}
