<?php

namespace App\Console\Commands;

use App\Services\PlaceToiletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConvertPlacesV2Command extends Command
{
    protected $signature = 'app:convert-places-v2
                            {--dry-run : Preview changes without writing to database}';

    protected $description = 'Convert existing place_opening_hours properties and places table data to the Places API (New) structure';

    private bool $dryRun;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('DRY-RUN mode: Only reading data, no changes will be made.');
        } else {
            $this->warn('Converting place_opening_hours and places data to Places API (New) format...');
        }

        $this->convertToiletOpeningHours();
        $this->convertPlacesData();

        $this->info($this->dryRun ? 'Dry run completed.' : 'Conversion completed successfully.');

        return self::SUCCESS;
    }

    private function convertToiletOpeningHours(): void
    {
        $this->info('Checking toilet_properties place_opening_hours records...');

        $rows = DB::table('toilet_properties')
            ->where('type', 'place_opening_hours')
            ->get();

        $convertedCount = 0;
        $unchangedCount = 0;

        foreach ($rows as $row) {
            $raw = $row->value;
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                continue;
            }

            $normalized = PlaceToiletService::normalizePeriodPoints($decoded);
            $newJson = json_encode($normalized);

            if ($newJson !== $raw) {
                $convertedCount++;
                if (! $this->dryRun) {
                    DB::table('toilet_properties')
                        ->where('fk_toiletId', $row->fk_toiletId)
                        ->where('type', 'place_opening_hours')
                        ->update(['value' => $newJson]);
                }
            } else {
                $unchangedCount++;
            }
        }

        $this->line("  place_opening_hours: {$convertedCount} to convert, {$unchangedCount} already in new format.");
    }

    private function convertPlacesData(): void
    {
        $this->info('Checking places table records...');

        if (! DB::getSchemaBuilder()->hasTable('places')) {
            $this->warn('  places table does not exist, skipping.');

            return;
        }

        $rows = DB::table('places')->get();
        $convertedCount = 0;
        $unchangedCount = 0;

        foreach ($rows as $row) {
            $raw = $row->data;
            $data = json_decode($raw, true);

            if (! is_array($data)) {
                continue;
            }

            $converted = $this->convertPlaceRecord($row->place_id, $data);
            $newJson = json_encode($converted);

            if ($newJson !== $raw) {
                $convertedCount++;
                if (! $this->dryRun) {
                    DB::table('places')
                        ->where('place_id', $row->place_id)
                        ->update(['data' => $newJson]);
                }
            } else {
                $unchangedCount++;
            }
        }

        $this->line("  places.data: {$convertedCount} to convert, {$unchangedCount} already in new format.");
    }

    private function convertPlaceRecord(string $placeId, array $data): array
    {
        $id = $data['id'] ?? $data['place_id'] ?? $placeId;

        // Display name
        if (isset($data['displayName']['text'])) {
            $displayName = $data['displayName'];
        } elseif (isset($data['displayName']) && is_string($data['displayName'])) {
            $displayName = ['text' => $data['displayName'], 'languageCode' => 'de'];
        } elseif (! empty($data['name'])) {
            $displayName = ['text' => $data['name'], 'languageCode' => 'de'];
        } else {
            $displayName = null;
        }

        // Formatted address
        $formattedAddress = $data['formattedAddress'] ?? $data['formatted_address'] ?? null;

        // Location
        $lat = $data['location']['latitude'] ?? $data['location']['lat'] ?? $data['geometry']['location']['lat'] ?? null;
        $lng = $data['location']['longitude'] ?? $data['location']['lng'] ?? $data['geometry']['location']['lng'] ?? null;
        $location = ($lat !== null && $lng !== null) ? [
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ] : null;

        // Website
        $websiteUri = $data['websiteUri'] ?? $data['website'] ?? null;

        // Opening hours
        $rawPeriods = $data['regularOpeningHours']['periods']
            ?? $data['opening_hours']['periods']
            ?? $data['result']['opening_hours']['periods']
            ?? null;

        $regularOpeningHours = null;
        if (is_array($rawPeriods)) {
            $periods = PlaceToiletService::normalizePeriodPoints($rawPeriods);
            $regularOpeningHours = ['periods' => $periods];
            if (isset($data['regularOpeningHours']['openNow']) || isset($data['opening_hours']['open_now'])) {
                $regularOpeningHours['openNow'] = (bool) ($data['regularOpeningHours']['openNow'] ?? $data['opening_hours']['open_now'] ?? false);
            }
            if (isset($data['regularOpeningHours']['weekdayDescriptions']) || isset($data['opening_hours']['weekday_text'])) {
                $regularOpeningHours['weekdayDescriptions'] = $data['regularOpeningHours']['weekdayDescriptions'] ?? $data['opening_hours']['weekday_text'];
            }
        }

        // Types
        $types = $data['types'] ?? null;
        if ($types === null && isset($data['type'])) {
            $types = is_array($data['type']) ? $data['type'] : [$data['type']];
        }

        $result = ['id' => $id];

        if ($displayName !== null) {
            $result['displayName'] = $displayName;
        }
        if ($formattedAddress !== null) {
            $result['formattedAddress'] = $formattedAddress;
        }
        if ($location !== null) {
            $result['location'] = $location;
        }
        if ($websiteUri !== null) {
            $result['websiteUri'] = $websiteUri;
        }
        if ($regularOpeningHours !== null) {
            $result['regularOpeningHours'] = $regularOpeningHours;
        }
        if ($types !== null) {
            $result['types'] = $types;
        }

        return $result;
    }
}
