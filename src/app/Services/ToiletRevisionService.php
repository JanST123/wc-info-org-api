<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Toilet;
use App\Models\ToiletRevision;
use Illuminate\Support\Facades\DB;

class ToiletRevisionService
{
    /**
     * Record a snapshot revision for a toilet and its properties.
     */
    public function recordRevision(
        Toilet $toilet,
        string $source = 'edit',
        ?array $diff = null,
        ?string $summary = null
    ): ToiletRevision {
        // Build toilet attributes snapshot
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

        // Build properties snapshot
        $propertiesData = DB::table('toilet_properties')
            ->where('fk_toiletId', $toilet->id)
            ->pluck('value', 'type')
            ->toArray();

        // Determine next version number
        $latestRev = ToiletRevision::where('toilet_id', $toilet->id)
            ->orderByDesc('version')
            ->first();

        if ($latestRev) {
            $nextVersion = $latestRev->version + 1;
        } else {
            $nextVersion = max(1, (int) ($toilet->version ?: 1));
        }

        // Update toilet current version counter
        DB::table('toilets')
            ->where('id', $toilet->id)
            ->update(['version' => $nextVersion]);
        $toilet->version = $nextVersion;

        // Automatically compute diff if none was explicitly passed
        if ($diff === null && $latestRev) {
            $diff = $this->calculateDiff(
                $latestRev->toilet_data ?? [],
                $latestRev->properties_data ?? [],
                $toiletData,
                $propertiesData
            );
        }

        return ToiletRevision::create([
            'toilet_id' => $toilet->id,
            'version' => $nextVersion,
            'source' => $source,
            'toilet_data' => $toiletData,
            'properties_data' => $propertiesData,
            'diff' => $diff ?: null,
            'summary' => $summary,
            'created_at' => now(),
        ]);
    }

    /**
     * Restore a toilet and its properties to a specific previous version.
     */
    public function restoreRevision(Toilet $toilet, int $targetVersion): ToiletRevision
    {
        $targetRev = ToiletRevision::where('toilet_id', $toilet->id)
            ->where('version', $targetVersion)
            ->firstOrFail();

        $data = $targetRev->toilet_data;
        $props = $targetRev->properties_data ?? [];

        // Apply toilet fields
        $toilet->name = $data['name'] ?? null;
        $toilet->owner = $data['owner'] ?? null;
        $toilet->lat = isset($data['lat']) && $data['lat'] !== null ? (float) $data['lat'] : null;
        $toilet->lon = isset($data['lon']) && $data['lon'] !== null ? (float) $data['lon'] : null;
        $toilet->place_id = $data['place_id'] ?? null;
        $toilet->status = $data['status'] ?? 'active';
        $toilet->is_qualified = (bool) ($data['is_qualified'] ?? false);
        $toilet->flagged = (bool) ($data['flagged'] ?? false);
        $toilet->contact_email = $data['contact_email'] ?? null;
        $toilet->source = $data['source'] ?? null;
        $toilet->user_overridden = $data['user_overridden'] ?? null;
        $toilet->save();

        // Restore properties table
        DB::table('toilet_properties')->where('fk_toiletId', $toilet->id)->delete();
        foreach ($props as $type => $value) {
            DB::table('toilet_properties')->insert([
                'fk_toiletId' => $toilet->id,
                'type' => $type,
                'value' => (string) $value,
                'user_overridden' => 1,
                'updated' => now(),
            ]);
        }

        return $this->recordRevision(
            $toilet,
            'restore',
            null,
            "Restored from version {$targetVersion}"
        );
    }

    /**
     * Calculate field differences between two version snapshots.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function calculateDiff(
        array $oldToilet,
        array $oldProps,
        array $newToilet,
        array $newProps
    ): array {
        $diff = [];

        foreach ($newToilet as $key => $newVal) {
            $oldVal = $oldToilet[$key] ?? null;
            if ($oldVal != $newVal) {
                $diff[$key] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        $allPropTypes = array_unique(array_merge(array_keys($oldProps), array_keys($newProps)));
        foreach ($allPropTypes as $type) {
            $oldVal = $oldProps[$type] ?? null;
            $newVal = $newProps[$type] ?? null;
            if ($oldVal !== $newVal) {
                $diff[$type] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        return $diff;
    }
}
