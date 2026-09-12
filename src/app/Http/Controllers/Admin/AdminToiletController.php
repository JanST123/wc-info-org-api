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
use App\Services\ToiletRevisionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
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
        private ToiletRevisionService $revisionService,
        private \App\Services\GeminiPlaceMatchingService $aiService,
    ) {}

    /**
     * Dashboard: List toilets added in the last 24 hours, flagged toilets list, cost summary and quick stats.
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

        $flaggedToilets = Toilet::where('flagged', 1)
            ->with(['properties', 'place'])
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'flagged_page');

        $stats = [
            'total' => Toilet::count(),
            'active' => Toilet::where('status', 'active')->count(),
            'hidden' => Toilet::where('status', 'hidden')->count(),
            'deleted' => Toilet::where('status', 'deleted')->count(),
            'qualified' => Toilet::where('is_qualified', 1)->count(),
            'flagged_count' => Toilet::where('flagged', 1)->count(),
            'recent_count' => $recentToilets->count(),
            'current_month_cost' => $this->costService->getCurrentMonthCost(),
            'monthly_budget' => $this->costService->getMonthlyBudget(),
            'is_budget_exceeded' => ! $this->costService->hasBudget(),
        ];

        return view('admin.index', compact('recentToilets', 'flaggedToilets', 'stats', 'since'));
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

        $revisions = $toilet->revisions()->get();

        return view('admin.toilets.show', compact(
            'toilet',
            'lastDiff',
            'nearbyPlaces',
            'propertyValues',
            'customProperties',
            'revisions'
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
            'use_place_coordinates' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,hidden,deleted'],
            'is_qualified' => ['nullable', 'boolean'],
            'flagged' => ['nullable', 'boolean'],
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

        // Check coordinates or use place coordinates
        $usePlaceCoordinates = $request->boolean('use_place_coordinates');

        if ($usePlaceCoordinates && ! empty($toilet->place_id)) {
            $placeModel = Place::find($toilet->place_id);
            $placeData = null;

            if ($placeModel) {
                $placeData = is_array($placeModel->data) ? $placeModel->data : json_decode((string) $placeModel->data, true);
            }

            if (empty($placeData['location']) && empty($placeData['geometry']['location'])) {
                try {
                    $fetched = $this->placesService->fetchPlaceDetails($toilet->place_id);
                    if ($fetched) {
                        Place::updateOrCreate(
                            ['place_id' => $toilet->place_id],
                            ['data' => $fetched]
                        );
                        $placeData = $fetched;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to fetch place details for use_place_coordinates', [
                        'place_id' => $toilet->place_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (is_array($placeData)) {
                $placeLat = $placeData['location']['latitude'] ?? $placeData['location']['lat'] ?? $placeData['geometry']['location']['lat'] ?? null;
                $placeLon = $placeData['location']['longitude'] ?? $placeData['location']['lng'] ?? $placeData['geometry']['location']['lng'] ?? null;

                if ($placeLat !== null && $placeLon !== null) {
                    $placeLat = (float) $placeLat;
                    $placeLon = (float) $placeLon;

                    if ($placeLat !== $toilet->lat) {
                        $diff['lat'] = ['old' => $toilet->lat, 'new' => $placeLat];
                        $toilet->lat = $placeLat;
                    }

                    if ($placeLon !== $toilet->lon) {
                        $diff['lon'] = ['old' => $toilet->lon, 'new' => $placeLon];
                        $toilet->lon = $placeLon;
                    }

                    $toilet->unmarkUserOverridden('lat', 'lon');
                }
            }
        } else {
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
        }

        // is_qualified
        $isQualified = $request->boolean('is_qualified');
        if ((bool) $toilet->is_qualified !== $isQualified) {
            $diff['is_qualified'] = ['old' => (bool) $toilet->is_qualified, 'new' => $isQualified];
            $toilet->is_qualified = $isQualified;
            $overriddenFields[] = 'is_qualified';
        }

        // flagged
        $flagged = $request->boolean('flagged');
        if ((bool) $toilet->flagged !== $flagged) {
            $diff['flagged'] = ['old' => (bool) $toilet->flagged, 'new' => $flagged];
            $toilet->flagged = $flagged;
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
            $this->revisionService->recordRevision($toilet, 'admin_edit', $diff, 'Admin updated toilet details');
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
     * Lazy-load nearby Google places for a toilet (~40m) on demand.
     */
    public function getNearbyPlaces(int $id, Request $request): JsonResponse
    {
        $toilet = Toilet::with('place')->find($id);
        if (! $toilet) {
            return response()->json(['error' => 'Toilet not found', 'places' => []], 404);
        }

        if ($toilet->lat === null || $toilet->lon === null) {
            return response()->json(['places' => []]);
        }

        if (! $this->costService->hasBudget()) {
            return response()->json([
                'error' => 'Monthly Google Cloud API budget exceeded',
                'places' => [],
            ], 429);
        }

        $places = [];
        $seenPlaceIds = [];

        // 1. Include current place if assigned
        if (! empty($toilet->place_id)) {
            $currentPlaceName = $toilet->place?->getName();
            $currentAddress = $toilet->place?->data['formattedAddress']
                ?? $toilet->place?->data['formatted_address']
                ?? '';

            $places[] = [
                'place_id' => $toilet->place_id,
                'name' => $currentPlaceName ?: $toilet->place_id,
                'address' => $currentAddress,
                'distance_m' => 0.0,
                'is_current' => true,
            ];
            $seenPlaceIds[$toilet->place_id] = true;
        }

        // 2. Fetch nearby places (~40m = 0.04km)
        try {
            $rawPlaces = $this->placesService->nearbySearchRaw($toilet->lat, $toilet->lon, 0.04);

            foreach ($rawPlaces as $item) {
                $pid = $item['id'] ?? $item['place_id'] ?? null;
                if (! $pid) {
                    continue;
                }

                // Cache in places table
                Place::updateOrCreate(
                    ['place_id' => $pid],
                    ['data' => $item]
                );

                if (isset($seenPlaceIds[$pid])) {
                    continue;
                }

                $name = $item['displayName']['text']
                    ?? (is_string($item['displayName'] ?? null) ? $item['displayName'] : null)
                    ?? (! str_starts_with((string) ($item['name'] ?? ''), 'places/') ? ($item['name'] ?? null) : null)
                    ?? $pid;
                $address = $item['formattedAddress']
                    ?? $item['formatted_address']
                    ?? '';

                $pLat = $item['location']['latitude'] ?? $item['location']['lat'] ?? $item['geometry']['location']['lat'] ?? null;
                $pLon = $item['location']['longitude'] ?? $item['location']['lng'] ?? $item['geometry']['location']['lng'] ?? null;

                $dist = null;
                if ($pLat !== null && $pLon !== null) {
                    $dist = $this->calculateDistanceMeters(
                        $toilet->lat,
                        $toilet->lon,
                        (float) $pLat,
                        (float) $pLon
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
            Log::warning('Admin getNearbyPlaces Google API call failed', [
                'toilet_id' => $toilet->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['places' => $places]);
    }

    /**
     * Directly assign a place_id to a toilet.
     */
    public function assignPlace(int $id, Request $request): JsonResponse|RedirectResponse
    {
        $toilet = Toilet::findOrFail($id);
        $newPlaceId = $request->input('place_id') ? trim((string) $request->input('place_id')) : null;
        if ($newPlaceId === '') {
            $newPlaceId = null;
        }

        $oldPlaceId = $toilet->place_id;

        if ($oldPlaceId !== $newPlaceId) {
            $toilet->place_id = $newPlaceId;
            $toilet->markUserOverridden('place_id');

            $toilet->update([
                'email_sent' => 2,
                'last_diff' => json_encode(['place_id' => ['old' => $oldPlaceId, 'new' => $newPlaceId]]),
            ]);

            $this->revisionService->recordRevision(
                $toilet,
                'admin_place_assign',
                ['place_id' => ['old' => $oldPlaceId, 'new' => $newPlaceId]],
                'Place assigned via admin'
            );
        }

        $placeName = null;
        if (! empty($toilet->place_id)) {
            $placeModel = Place::find($toilet->place_id);
            $placeName = $placeModel?->getName() ?? $toilet->place_id;
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'toilet_id' => $toilet->id,
                'place_id' => $toilet->place_id,
                'place_name' => $placeName ?: ($toilet->place_id ?? '-'),
                'message' => 'Place assigned successfully.',
            ]);
        }

        return redirect()->back()->with('success', "Place assigned for Toilet #{$toilet->id}.");
    }

    /**
     * Use Gemini AI to find and suggest a matching Google Place for a toilet.
     */
    public function aiSuggestPlace(int $id): JsonResponse
    {
        $toilet = Toilet::find($id);
        if (! $toilet) {
            return response()->json(['success' => false, 'error' => 'Toilet not found'], 404);
        }

        $result = $this->aiService->suggestPlace($toilet);

        if (! empty($result['error'])) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    /**
     * Accept and assign the AI-suggested Google Place to a toilet.
     */
    public function aiAcceptPlace(int $id, Request $request): JsonResponse
    {
        $toilet = Toilet::findOrFail($id);
        $newPlaceId = $request->input('place_id') ? trim((string) $request->input('place_id')) : null;

        if (empty($newPlaceId)) {
            return response()->json(['success' => false, 'error' => 'No place_id provided'], 422);
        }

        $oldPlaceId = $toilet->place_id;
        $diff = [];

        if ($oldPlaceId !== $newPlaceId) {
            $diff['place_id'] = ['old' => $oldPlaceId, 'new' => $newPlaceId];
            $toilet->place_id = $newPlaceId;
            $toilet->markUserOverridden('place_id');
            $toilet->save();
        }

        // Set public_accessible property if requested
        $setPublicAccessible = $request->boolean('set_public_accessible');
        if ($setPublicAccessible) {
            $this->saveFlagProperty($toilet->id, 'public_accessible', true, $diff);
        }

        if (! empty($diff)) {
            $toilet->update([
                'email_sent' => 2,
                'last_diff' => json_encode($diff),
            ]);

            $this->revisionService->recordRevision(
                $toilet,
                'admin_ai_place_assign',
                $diff,
                'AI-suggested Google Place assigned'
            );
        }

        $placeModel = Place::find($toilet->place_id);
        $placeName = $placeModel?->getName() ?? $toilet->place_id;

        return response()->json([
            'success' => true,
            'toilet_id' => $toilet->id,
            'place_id' => $toilet->place_id,
            'place_name' => $placeName,
            'public_accessible' => $setPublicAccessible,
            'message' => 'AI suggestion applied and place assigned successfully.',
        ]);
    }

    /**
     * Restore toilet to a previous version.
     */
    public function restoreVersion(int $id, int $version): RedirectResponse
    {
        $toilet = Toilet::findOrFail($id);
        $this->revisionService->restoreRevision($toilet, $version);

        return redirect()->route('admin.toilets.show', ['id' => $toilet->id])
            ->with('success', "Toilet #{$toilet->id} was restored to version {$version} successfully (created version {$toilet->version}).");
    }

    /**
     * Unflag a toilet (set flagged = false).
     */
    public function unflag(int $id, Request $request): JsonResponse|RedirectResponse
    {
        $toilet = Toilet::findOrFail($id);
        $toilet->flagged = false;
        $toilet->save();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'toilet_id' => $toilet->id,
                'flagged' => false,
                'message' => "Toilet #{$toilet->id} unflagged.",
            ]);
        }

        return redirect()->back()->with('success', "Toilet #{$toilet->id} unflagged.");
    }

    /**
     * Toggle or set the flagged status of a toilet.
     */
    public function toggleFlag(int $id, Request $request): JsonResponse|RedirectResponse
    {
        $toilet = Toilet::findOrFail($id);
        $newFlagged = $request->has('flagged') ? $request->boolean('flagged') : ! (bool) $toilet->flagged;
        $toilet->flagged = $newFlagged;
        $toilet->save();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'toilet_id' => $toilet->id,
                'flagged' => (bool) $toilet->flagged,
                'message' => "Toilet #{$toilet->id} flag updated.",
            ]);
        }

        return redirect()->back()->with('success', "Toilet #{$toilet->id} flag updated.");
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
            $currentPlaceName = null;
            $currentPlaceAddress = '';
            $currentPlaceLat = null;
            $currentPlaceLon = null;

            $placeModel = $toilet->place ?? Place::find($toilet->place_id);

            if ($placeModel) {
                $data = is_array($placeModel->data) ? $placeModel->data : json_decode((string) $placeModel->data, true);
                if (is_array($data)) {
                    $currentPlaceName = $data['displayName']['text']
                        ?? (is_string($data['displayName'] ?? null) ? $data['displayName'] : null)
                        ?? (! str_starts_with((string) ($data['name'] ?? ''), 'places/') ? ($data['name'] ?? null) : null);
                    $currentPlaceAddress = $data['formattedAddress']
                        ?? $data['formatted_address']
                        ?? '';
                    $currentPlaceLat = $data['location']['latitude'] ?? $data['location']['lat'] ?? $data['geometry']['location']['lat'] ?? null;
                    $currentPlaceLon = $data['location']['longitude'] ?? $data['location']['lng'] ?? $data['geometry']['location']['lng'] ?? null;
                }
            }

            // If place details not found locally or name missing, try fetching from Google Places API
            if (empty($currentPlaceName)) {
                try {
                    $fetched = $this->placesService->fetchPlaceDetails($toilet->place_id);
                    if ($fetched) {
                        Place::updateOrCreate(
                            ['place_id' => $toilet->place_id],
                            ['data' => $fetched]
                        );

                        $currentPlaceName = $fetched['displayName']['text']
                            ?? (is_string($fetched['displayName'] ?? null) ? $fetched['displayName'] : null)
                            ?? (! str_starts_with((string) ($fetched['name'] ?? ''), 'places/') ? ($fetched['name'] ?? null) : null);
                        $currentPlaceAddress = $fetched['formattedAddress']
                            ?? $fetched['formatted_address']
                            ?? '';
                        $currentPlaceLat = $fetched['location']['latitude'] ?? $fetched['location']['lat'] ?? $fetched['geometry']['location']['lat'] ?? null;
                        $currentPlaceLon = $fetched['location']['longitude'] ?? $fetched['location']['lng'] ?? $fetched['geometry']['location']['lng'] ?? null;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to fetch place details for toilet current place', [
                        'place_id' => $toilet->place_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Fallback if still empty: toilet owner / name or place ID
            if (empty($currentPlaceName)) {
                $currentPlaceName = ! empty($toilet->owner)
                    ? $toilet->owner
                    : (! empty($toilet->name) && $toilet->name !== 'Toilette' && ! str_starts_with($toilet->name, 'WC #') ? $toilet->name : $toilet->place_id);
            }

            $places[] = [
                'place_id' => $toilet->place_id,
                'name' => $currentPlaceName,
                'address' => $currentPlaceAddress,
                'lat' => $currentPlaceLat !== null ? (float) $currentPlaceLat : null,
                'lon' => $currentPlaceLon !== null ? (float) $currentPlaceLon : null,
                'distance_m' => 0.0,
                'is_current' => true,
            ];
            $seenPlaceIds[$toilet->place_id] = true;
        }

        // 2. If lat/lon are set, query Google Places around 100m (0.1 km)
        if ($toilet->lat !== null && $toilet->lon !== null) {
            try {
                $rawPlaces = $this->placesService->nearbySearchRaw($toilet->lat, $toilet->lon, 0.1);

                foreach ($rawPlaces as $item) {
                    $pid = $item['id'] ?? $item['place_id'] ?? null;
                    if (! $pid || isset($seenPlaceIds[$pid])) {
                        continue;
                    }

                    $name = $item['displayName']['text']
                        ?? (is_string($item['displayName'] ?? null) ? $item['displayName'] : null)
                        ?? (! str_starts_with((string) ($item['name'] ?? ''), 'places/') ? ($item['name'] ?? null) : null)
                        ?? $pid;
                    $address = $item['formattedAddress']
                        ?? $item['formatted_address']
                        ?? '';

                    $pLat = $item['location']['latitude'] ?? $item['location']['lat'] ?? $item['geometry']['location']['lat'] ?? null;
                    $pLon = $item['location']['longitude'] ?? $item['location']['lng'] ?? $item['geometry']['location']['lng'] ?? null;

                    $dist = null;
                    if ($pLat !== null && $pLon !== null) {
                        $dist = $this->calculateDistanceMeters(
                            $toilet->lat,
                            $toilet->lon,
                            (float) $pLat,
                            (float) $pLon
                        );
                    }

                    $places[] = [
                        'place_id' => $pid,
                        'name' => $name,
                        'address' => $address,
                        'lat' => $pLat !== null ? (float) $pLat : null,
                        'lon' => $pLon !== null ? (float) $pLon : null,
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
