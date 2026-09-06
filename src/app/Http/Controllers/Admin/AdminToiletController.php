<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Models\ToiletProperty;
use App\Services\GoogleCostService;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use App\Services\S3PhotoStorageService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminToiletController extends Controller
{
    public function __construct(
        private GooglePlacesService $placesService,
        private S3PhotoStorageService $s3,
        private GoogleCostService $costService,
    ) {}

    /**
     * Dashboard: List toilets added in the last 24 hours, cost summary and quick stats.
     */
    public function index(Request $request): View
    {
        $since = Carbon::now()->subHours(24);

        $recentToilets = Toilet::where(function ($query) use ($since) {
            $query->where('created_at', '>=', $since)
                ->orWhere(function ($q) use ($since) {
                    $q->whereNull('created_at')
                        ->where('updated', '>=', $since);
                });
        })
            ->with(['properties', 'photos'])
            ->orderByDesc('id')
            ->get();

        $stats = [
            'total' => Toilet::count(),
            'active' => Toilet::where('status', 'active')->count(),
            'hidden' => Toilet::where('status', 'hidden')->count(),
            'deleted' => Toilet::where('status', 'deleted')->count(),
            'qualified' => Toilet::where('is_qualified', 1)->count(),
            'recent_count' => $recentToilets->count(),
            'current_month_cost' => $this->costService->getCurrentMonthCost(),
            'monthly_budget' => $this->costService->getMonthlyBudget(),
            'is_budget_exceeded' => ! $this->costService->hasBudget(),
        ];

        return view('admin.index', compact('recentToilets', 'stats', 'since'));
    }

    /**
     * Google API Cost Control & Monitoring Dashboard.
     */
    public function costs(): View
    {
        $monthlyStats = $this->costService->getMonthlyStats();
        $recentLogs = $this->costService->getRecentLogs(100);

        return view('admin.costs', compact('monthlyStats', 'recentLogs'));
    }

    /**
     * Update monthly Google API budget.
     */
    public function updateBudget(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'budget' => ['required', 'numeric', 'min:0', 'max:10000'],
        ]);

        $this->costService->setMonthlyBudget((float) $validated['budget']);

        return redirect()->route('admin.costs')
            ->with('success', 'Monthly Google API budget updated to $'.number_format((float) $validated['budget'], 2).' USD.');
    }

    /**
     * Quick search / jump to a toilet by ID.
     */
    public function find(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $toilet = Toilet::find($validated['id']);

        if (! $toilet) {
            return redirect()->route('admin.index')
                ->with('error', "Toilet with ID #{$validated['id']} was not found.");
        }

        return redirect()->route('admin.toilets.show', ['id' => $toilet->id]);
    }

    /**
     * View and edit a single toilet.
     */
    public function show(int $id): View
    {
        $toilet = Toilet::with(['properties', 'photos', 'place'])->findOrFail($id);

        $lastDiff = null;
        if (! empty($toilet->last_diff)) {
            $lastDiff = is_array($toilet->last_diff)
                ? $toilet->last_diff
                : json_decode((string) $toilet->last_diff, true);
        }

        $nearbyPlaces = $this->fetchNearbyPlaces($toilet);

        // Pre-build map of known properties
        $propertyValues = [];
        $customProperties = [];
        $knownTypes = ToiletProperty::TYPES;

        foreach ($toilet->properties as $prop) {
            if (in_array($prop->type, $knownTypes, true)) {
                $propertyValues[$prop->type] = $prop->value;
            } else {
                $customProperties[] = [
                    'type' => $prop->type,
                    'value' => $prop->value,
                    'user_overridden' => $prop->user_overridden,
                ];
            }
        }

        return view('admin.toilets.show', compact(
            'toilet',
            'lastDiff',
            'nearbyPlaces',
            'propertyValues',
            'customProperties'
        ));
    }

    /**
     * Update toilet details and properties.
     */
    public function update(int $id, Request $request): RedirectResponse
    {
        $toilet = Toilet::with('properties')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'owner' => ['nullable', 'string', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
            'place_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,hidden,deleted'],
            'is_qualified' => ['nullable', 'boolean'],
            'contact_email' => ['nullable', 'email', 'max:200'],
            'source' => ['nullable', 'string', 'max:45'],

            // Flag properties
            'is_unisex' => ['nullable', 'boolean'],
            'is_gender_separated' => ['nullable', 'boolean'],
            'has_wheelchair_access' => ['nullable', 'boolean'],
            'has_changing_table' => ['nullable', 'boolean'],
            'accessible_outside_opening_times' => ['nullable', 'boolean'],
            'public_accessible' => ['nullable', 'boolean'],

            // Value properties
            'euro_key' => ['nullable', 'string', 'in:,yes,no,euro_only,unknown'],
            'storage_space' => ['nullable', 'string', 'in:,none,little,much'],
            'address' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string'],
            'place_opening_hours' => ['nullable', 'string'],

            // Dynamic custom properties
            'custom_property_type' => ['nullable', 'array'],
            'custom_property_type.*' => ['nullable', 'string', 'max:50'],
            'custom_property_value' => ['nullable', 'array'],
            'custom_property_value.*' => ['nullable', 'string'],
        ]);

        $diff = [];
        $overriddenFields = [];

        // Check main fields
        $fields = ['name', 'owner', 'place_id', 'status', 'contact_email', 'source'];
        foreach ($fields as $field) {
            $val = $validated[$field] ?? null;
            if ($val !== $toilet->$field) {
                $diff[$field] = ['old' => $toilet->$field, 'new' => $val];
                $toilet->$field = $val;
                $overriddenFields[] = $field;
            }
        }

        // Check coordinates
        if (array_key_exists('lat', $validated)) {
            $latVal = $validated['lat'] !== null ? (float) $validated['lat'] : null;
            if ($latVal !== $toilet->lat) {
                $diff['lat'] = ['old' => $toilet->lat, 'new' => $latVal];
                $toilet->lat = $latVal;
                $overriddenFields[] = 'lat';
            }
        }

        if (array_key_exists('lon', $validated)) {
            $lonVal = $validated['lon'] !== null ? (float) $validated['lon'] : null;
            if ($lonVal !== $toilet->lon) {
                $diff['lon'] = ['old' => $toilet->lon, 'new' => $lonVal];
                $toilet->lon = $lonVal;
                $overriddenFields[] = 'lon';
            }
        }

        // is_qualified
        $isQualified = $request->boolean('is_qualified');
        if ((bool) $toilet->is_qualified !== $isQualified) {
            $diff['is_qualified'] = ['old' => (bool) $toilet->is_qualified, 'new' => $isQualified];
            $toilet->is_qualified = $isQualified;
            $overriddenFields[] = 'is_qualified';
        }

        if ($toilet->isDirty()) {
            $toilet->save();
        }

        if (! empty($overriddenFields)) {
            $toilet->markUserOverridden(...$overriddenFields);
        }

        // Update boolean flags
        $flagFields = [
            'is_unisex',
            'is_gender_separated',
            'has_wheelchair_access',
            'has_changing_table',
            'accessible_outside_opening_times',
            'public_accessible',
        ];

        foreach ($flagFields as $flag) {
            $val = $request->boolean($flag);
            $this->saveFlagProperty($toilet->id, $flag, $val, $diff);
        }

        // Update value properties
        $valProps = ['euro_key', 'storage_space', 'address', 'website', 'comment'];
        foreach ($valProps as $prop) {
            $val = $validated[$prop] ?? null;
            $this->saveTextProperty($toilet->id, $prop, $val, $diff);
        }

        // Handle opening hours
        $openingHoursVal = $validated['place_opening_hours'] ?? null;
        if ($openingHoursVal !== null && trim($openingHoursVal) !== '') {
            $decoded = json_decode($openingHoursVal, true);
            if (is_array($decoded)) {
                $normalized = PlaceToiletService::normalizePeriodPoints($decoded);
                $this->saveTextProperty($toilet->id, 'place_opening_hours', json_encode($normalized), $diff);
            } else {
                $this->saveTextProperty($toilet->id, 'place_opening_hours', $openingHoursVal, $diff);
            }
        } else {
            $this->saveTextProperty($toilet->id, 'place_opening_hours', null, $diff);
        }

        // Handle custom properties
        if (! empty($validated['custom_property_type']) && is_array($validated['custom_property_type'])) {
            foreach ($validated['custom_property_type'] as $idx => $propType) {
                $propType = trim((string) $propType);
                $propValue = (string) ($validated['custom_property_value'][$idx] ?? '');

                if ($propType !== '' && in_array($propType, ToiletProperty::TYPES, true)) {
                    $this->saveTextProperty($toilet->id, $propType, $propValue, $diff);
                }
            }
        }

        if (count($diff) > 0) {
            $toilet->update([
                'email_sent' => 2,
                'last_diff' => json_encode($diff),
            ]);
        }

        return redirect()->route('admin.toilets.show', ['id' => $toilet->id])
            ->with('success', "Toilet #{$toilet->id} was updated successfully.");
    }

    /**
     * Reschedule toilet for discovery job by setting last_included to NOW().
     */
    public function rescheduleDiscovery(int $id): RedirectResponse
    {
        $toilet = Toilet::findOrFail($id);
        $toilet->update(['last_included' => now()]);

        return redirect()->route('admin.toilets.show', ['id' => $toilet->id])
            ->with('success', "Toilet #{$toilet->id} rescheduled for discovery (last_included timestamp set to NOW()).");
    }

    /**
     * Delete a toilet photo (soft or hard delete).
     */
    public function deletePhoto(int $id, string $filename, Request $request): RedirectResponse
    {
        $photo = ToiletPhoto::where('fk_toiletId', $id)
            ->where(function ($query) use ($filename) {
                $query->where('filename', $filename)
                    ->orWhere('filename', '_DELETED_'.$filename);
            })
            ->first();

        if (! $photo) {
            return redirect()->route('admin.toilets.show', ['id' => $id])
                ->with('error', "Photo '{$filename}' not found for Toilet #{$id}.");
        }

        $hardDelete = $request->boolean('hard', false);

        if ($hardDelete) {
            if ($this->s3->exists($id, $photo->filename)) {
                $this->s3->delete($id, $photo->filename);
            }
            if (! empty($photo->filename_thumb) && $this->s3->exists($id, $photo->filename_thumb)) {
                $this->s3->delete($id, $photo->filename_thumb);
            }

            $photo->delete();

            return redirect()->route('admin.toilets.show', ['id' => $id])
                ->with('success', "Photo '{$filename}' permanently deleted.");
        }

        // Soft delete
        $baseFilename = str_starts_with($photo->filename, '_DELETED_')
            ? substr($photo->filename, 9)
            : $photo->filename;
        $newFilename = '_DELETED_'.$baseFilename;

        if ($this->s3->exists($id, $photo->filename) && $photo->filename !== $newFilename) {
            $this->s3->rename($id, $photo->filename, $newFilename);
        }

        $newThumb = null;
        if (! empty($photo->filename_thumb)) {
            $baseThumb = str_starts_with($photo->filename_thumb, '_DELETED_')
                ? substr($photo->filename_thumb, 9)
                : $photo->filename_thumb;
            $newThumb = '_DELETED_'.$baseThumb;

            if ($this->s3->exists($id, $photo->filename_thumb) && $photo->filename_thumb !== $newThumb) {
                $this->s3->rename($id, $photo->filename_thumb, $newThumb);
            }
        }

        $photo->update([
            'filename' => $newFilename,
            'filename_thumb' => $newThumb ?? $photo->filename_thumb,
            'deleted_ts' => now(),
            'email_sent' => 2,
        ]);

        return redirect()->route('admin.toilets.show', ['id' => $id])
            ->with('success', "Photo '{$filename}' was soft-deleted.");
    }

    /**
     * Fetch Google Places and local places within ~40 meters around the toilet.
     *
     * @return array<int, array{place_id: string, name: string, address: string, distance_m: float|null}>
     */
    private function fetchNearbyPlaces(Toilet $toilet): array
    {
        $places = [];
        $seenPlaceIds = [];

        // 1. Include current place if assigned
        if (! empty($toilet->place_id)) {
            $currentPlaceName = 'Current Place';
            $currentPlaceAddress = '';

            if ($toilet->place && is_array($toilet->place->data)) {
                $data = $toilet->place->data;
                $currentPlaceName = $data['displayName']['text']
                    ?? (is_string($data['displayName'] ?? null) ? $data['displayName'] : null)
                    ?? $data['name']
                    ?? 'Current Place';
                $currentPlaceAddress = $data['formattedAddress']
                    ?? $data['formatted_address']
                    ?? '';
            }

            $places[] = [
                'place_id' => $toilet->place_id,
                'name' => $currentPlaceName,
                'address' => $currentPlaceAddress,
                'distance_m' => 0.0,
                'is_current' => true,
            ];
            $seenPlaceIds[$toilet->place_id] = true;
        }

        // 2. If lat/lon are set, query Google Places around 40m (0.04 km)
        if ($toilet->lat !== null && $toilet->lon !== null) {
            try {
                $rawPlaces = $this->placesService->nearbySearchRaw($toilet->lat, $toilet->lon, 0.04);

                foreach ($rawPlaces as $item) {
                    $pid = $item['id'] ?? $item['place_id'] ?? null;
                    if (! $pid || isset($seenPlaceIds[$pid])) {
                        continue;
                    }

                    $name = $item['displayName']['text']
                        ?? (is_string($item['displayName'] ?? null) ? $item['displayName'] : null)
                        ?? $item['name']
                        ?? $pid;
                    $address = $item['formattedAddress']
                        ?? $item['formatted_address']
                        ?? '';

                    $dist = null;
                    if (isset($item['location']['latitude'], $item['location']['longitude'])) {
                        $dist = $this->calculateDistanceMeters(
                            $toilet->lat,
                            $toilet->lon,
                            (float) $item['location']['latitude'],
                            (float) $item['location']['longitude']
                        );
                    }

                    $places[] = [
                        'place_id' => $pid,
                        'name' => $name,
                        'address' => $address,
                        'distance_m' => $dist,
                        'is_current' => false,
                    ];
                    $seenPlaceIds[$pid] = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Admin fetchNearbyPlaces Google API call failed', [
                    'toilet_id' => $toilet->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $places;
    }

    private function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 1);
    }

    private function saveFlagProperty(int $toiletId, string $flag, bool $value, array &$diff): void
    {
        $existing = DB::table('toilet_properties')
            ->where('fk_toiletId', $toiletId)
            ->where('type', $flag)
            ->first();

        $target = $value ? '1' : '0';

        if (! $existing || $existing->value !== $target) {
            $diff[$flag] = ['old' => $existing?->value ?: '0', 'new' => $target];
        }

        DB::table('toilet_properties')->updateOrInsert(
            ['fk_toiletId' => $toiletId, 'type' => $flag],
            ['value' => $target, 'user_overridden' => 1]
        );
    }

    private function saveTextProperty(int $toiletId, string $type, ?string $value, array &$diff): void
    {
        $existing = DB::table('toilet_properties')
            ->where('fk_toiletId', $toiletId)
            ->where('type', $type)
            ->first();

        if ($value === null || $value === '') {
            if ($existing && $existing->value !== '') {
                $diff[$type] = ['old' => $existing->value, 'new' => null];
            }

            DB::table('toilet_properties')->updateOrInsert(
                ['fk_toiletId' => $toiletId, 'type' => $type],
                ['value' => '', 'user_overridden' => 1]
            );

            return;
        }

        if (! $existing || $existing->value !== $value) {
            $diff[$type] = ['old' => $existing?->value ?: '-', 'new' => $value];
        }

        DB::table('toilet_properties')->updateOrInsert(
            ['fk_toiletId' => $toiletId, 'type' => $type],
            ['value' => $value, 'user_overridden' => 1]
        );
    }
}
