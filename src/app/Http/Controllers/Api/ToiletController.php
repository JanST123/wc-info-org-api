<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreToiletPropertiesRequest;
use App\Http\Requests\StoreToiletRequest;
use App\Http\Requests\UpdateToiletRequest;
use App\Http\Resources\ToiletDetailResource;
use App\Http\Resources\ToiletListResource;
use App\Models\Toilet;
use App\Models\Type;
use App\Models\TypeXPlace;
use App\Services\AdminLinkService;
use App\Services\GooglePlacesService;
use App\Services\OpeningHoursService;
use App\Services\PlaceToiletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ToiletController extends Controller
{
    public function __construct(
        private AdminLinkService $adminLink,
        private GooglePlacesService $placesService,
    ) {}

    /**
     * List all visible toilets for a given place.
     */
    public function forPlace(string $placeId): JsonResponse
    {
        $toilets = Toilet::visible()
            ->where('place_id', $placeId)
            ->with(['properties', 'photos'])
            ->get();

        $this->markIncluded($toilets);

        return response()->json(ToiletListResource::collection($toilets));
    }

    /**
     * List visible toilets within a geographic bounding box expanded by a distance buffer.
     *
     * If no toilets are found in the database, the API performs a synchronous
     * Google Places Nearby Search for the bounding box area and returns any newly discovered toilets.
     *
     * @queryParam distance float Distance buffer around the bounding box in kilometers (default: 40, min: 0.1, max: 200). Example: 40
     * @queryParam filter string Comma-separated key:value attribute filters (e.g. is_open:true,euro_key:yes). Example: is_open:true
     */
    public function forBounds(Request $request, float $south, float $west, float $north, float $east): JsonResponse
    {
        $validated = $request->validate([
            'distance' => ['sometimes', 'numeric', 'min:0.1', 'max:200'],
            'filter' => ['sometimes', 'string'],
        ]);

        $distance = (float) ($validated['distance'] ?? 40);
        $filter = $this->parseFilter($validated['filter'] ?? null);

        $minLon = min($west, $east);
        $maxLon = max($west, $east);
        $minLat = min($south, $north);
        $maxLat = max($south, $north);

        $earthRadius = 6371.0;
        $dLat = rad2deg($distance / $earthRadius);
        $expandedMinLat = max(-90.0, $minLat - $dLat);
        $expandedMaxLat = min(90.0, $maxLat + $dLat);

        $maxAbsLat = max(abs($expandedMinLat), abs($expandedMaxLat));
        $cosLat = cos(deg2rad($maxAbsLat));
        $dLon = $cosLat > 0.0001 ? rad2deg($distance / ($earthRadius * $cosLat)) : 180.0;
        $expandedMinLon = max(-180.0, $minLon - $dLon);
        $expandedMaxLon = min(180.0, $maxLon + $dLon);

        $toilets = Toilet::visible()
            ->whereBetween('lon', [$expandedMinLon, $expandedMaxLon])
            ->whereBetween('lat', [$expandedMinLat, $expandedMaxLat])
            ->with(['properties', 'photos'])
            ->get();

        if ($toilets->isEmpty()) {
            $centerLat = ($minLat + $maxLat) / 2;
            $centerLon = ($minLon + $maxLon) / 2;
            $searchDistance = $this->haversineDistance($centerLat, $centerLon, $expandedMaxLat, $expandedMaxLon);
            $searchDistance = max(0.1, min($searchDistance, 50.0));

            $discovered = $this->placesService->discoverToiletsNearby($centerLat, $centerLon, $searchDistance);

            $discovered = $discovered->filter(function (Toilet $toilet) use ($expandedMinLat, $expandedMaxLat, $expandedMinLon, $expandedMaxLon) {
                return $toilet->lat !== null && $toilet->lon !== null
                    && $toilet->lat >= $expandedMinLat && $toilet->lat <= $expandedMaxLat
                    && $toilet->lon >= $expandedMinLon && $toilet->lon <= $expandedMaxLon;
            })->values();

            $discovered = $this->applyFilters($discovered, $filter);
            $this->markIncluded($discovered);

            if ($discovered->isNotEmpty()) {
                return response()->json(ToiletListResource::collection($discovered));
            }
        } else {
            $toilets = $this->applyFilters($toilets, $filter);
            $this->markIncluded($toilets);
        }

        return response()->json(ToiletListResource::collection($toilets));
    }

    /**
     * Find toilets near a coordinate.
     *
     * If no toilets are found in the database, the API performs a synchronous
     * Google Places Nearby Search and returns any newly discovered toilets.
     *
     * @queryParam distance float Search radius in kilometers (default: 40, min: 0.1, max: 200). Example: 40
     * @queryParam filter string Comma-separated key:value attribute filters (e.g. is_open:true,euro_key:yes). Example: is_open:true
     */
    public function nearby(Request $request, float $lat, float $lon): JsonResponse
    {
        $validated = $request->validate([
            'distance' => ['sometimes', 'numeric', 'min:0.1', 'max:200'],
            'filter' => ['sometimes', 'string'],
        ]);

        $distance = (float) ($validated['distance'] ?? 40);
        $filter = $this->parseFilter($validated['filter'] ?? null);

        $toilets = Toilet::visible()
            ->selectRaw('*, (6371 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lon) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) AS distance', [$lat, $lon, $lat])
            ->having('distance', '<', $distance)
            ->orderBy('distance')
            ->with(['properties', 'photos'])
            ->get();

        if ($toilets->isEmpty()) {
            $discovered = $this->placesService->discoverToiletsNearby($lat, $lon, $distance);

            foreach ($discovered as $toilet) {
                $toilet->setAttribute('distance', $this->haversineDistance($lat, $lon, $toilet->lat, $toilet->lon));
            }

            $discovered = $discovered->sortBy('distance')->values();
            $discovered = $this->applyFilters($discovered, $filter);
            $this->markIncluded($discovered);

            if ($discovered->isNotEmpty()) {
                return response()->json(ToiletListResource::collection($discovered));
            }
        } else {
            $toilets = $this->applyFilters($toilets, $filter);
            $this->markIncluded($toilets);
        }

        return response()->json(ToiletListResource::collection($toilets));
    }

    private function markIncluded(Collection $toilets): void
    {
        $ids = $toilets->pluck('id')->filter()->unique()->all();

        if (count($ids) === 0) {
            return;
        }

        Toilet::whereIn('id', $ids)->update(['last_included' => now()]);
    }

    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function parseFilter(?string $filter): array
    {
        $result = [];

        if (empty($filter)) {
            return $result;
        }

        foreach (explode(',', $filter) as $part) {
            $part = trim($part);

            if (str_contains($part, ':')) {
                [$key, $value] = explode(':', $part, 2);
            } elseif (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
            } else {
                $key = $part;
                $value = 'true';
            }

            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            $result[$key] = strtolower($value);
        }

        return $result;
    }

    /**
     * @param  Collection<int, Toilet>  $toilets
     * @return Collection<int, Toilet>
     */
    private function applyFilters(Collection $toilets, array $filters): Collection
    {
        if (empty($filters)) {
            return $toilets;
        }

        $openingHours = app(OpeningHoursService::class);

        return $toilets->filter(function (Toilet $toilet) use ($filters, $openingHours) {
            $periods = $toilet->propertyValue('place_opening_hours');
            $hasPeriods = ! empty($periods) && ! empty(json_decode($periods, true));
            $state = $openingHours->getOpenState($periods);

            $toilet->setAttribute('is_open', $state['is_open']);
            $toilet->setAttribute('open_timestamp', $state['open_timestamp']);
            $toilet->setAttribute('close_timestamp', $state['close_timestamp']);

            foreach ($filters as $key => $rawVal) {
                $isTruthy = in_array($rawVal, ['1', 'true', 'yes'], true);
                $isFalsy = in_array($rawVal, ['0', 'false', 'no'], true);

                if ($key === 'is_open') {
                    $isAccessibleOutside = $toilet->isFlagSet('accessible_outside_opening_times');
                    $hasNoOpeningTimes = ! $hasPeriods;
                    $matchesOpen = $state['is_open'] || $isAccessibleOutside || $hasNoOpeningTimes;

                    if ($isTruthy && ! $matchesOpen) {
                        return false;
                    }
                    if ($isFalsy && $matchesOpen) {
                        return false;
                    }

                    continue;
                }

                if ($key === 'euro_key') {
                    $actualValue = $toilet->propertyValue('euro_key');
                    $targetValue = match ($rawVal) {
                        '1', 'true' => 'yes',
                        '0', 'false' => 'no',
                        default => $rawVal,
                    };

                    if ($actualValue !== $targetValue) {
                        return false;
                    }

                    continue;
                }

                if ($key === 'storage_space') {
                    $actualValue = $toilet->propertyValue('storage_space');
                    if ($actualValue !== $rawVal) {
                        return false;
                    }

                    continue;
                }

                // Boolean flags (public_accessible, has_wheelchair_access, has_changing_table, is_gender_separated, is_unisex, accessible_outside_opening_times)
                $flagSet = $toilet->isFlagSet($key);
                if ($isTruthy && ! $flagSet) {
                    return false;
                }
                if ($isFalsy && $flagSet) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Get a single toilet by ID.
     */
    public function byId(int $id): JsonResponse
    {
        $toilet = Toilet::with(['properties', 'photos'])->find($id);

        if (! $toilet) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $toilet->update(['last_included' => now()]);

        return response()->json(new ToiletDetailResource($toilet));
    }

    /**
     * Update an existing toilet.
     */
    public function update(int $id, UpdateToiletRequest $request): JsonResponse
    {
        $toilet = Toilet::findOrFail($id);
        $input = $request->validated();
        $diff = [];
        $userOverriddenFields = [];

        $mainFields = ['name', 'owner', 'place_id', 'status'];
        foreach ($mainFields as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== $toilet->$field) {
                $diff[$field] = ['old' => $toilet->$field, 'new' => $input[$field]];
                $toilet->$field = $input[$field];
                $userOverriddenFields[] = $field;
            }
        }

        if (array_key_exists('lat', $input) && $input['lat'] !== null) {
            if ($input['lat'] != $toilet->lat) {
                $diff['lat'] = ['old' => $toilet->lat, 'new' => $input['lat']];
                $toilet->lat = $input['lat'];
                $userOverriddenFields[] = 'lat';
            }
        }

        if (array_key_exists('lon', $input) && $input['lon'] !== null) {
            if ($input['lon'] != $toilet->lon) {
                $diff['lon'] = ['old' => $toilet->lon, 'new' => $input['lon']];
                $toilet->lon = $input['lon'];
                $userOverriddenFields[] = 'lon';
            }
        }

        if (array_key_exists('is_qualified', $input) && (bool) $input['is_qualified'] !== (bool) $toilet->is_qualified) {
            $diff['is_qualified'] = ['old' => $toilet->is_qualified, 'new' => $input['is_qualified']];
            $toilet->is_qualified = $input['is_qualified'];
        }

        if ($toilet->isDirty()) {
            $toilet->save();
        }

        if (! empty($userOverriddenFields)) {
            $toilet->markUserOverridden(...$userOverriddenFields);
        }

        if (isset($input['place_id'])) {
            $this->checkAndUpdatePlaceType($input['place_id']);
        }

        $flagFields = ['is_unisex', 'is_gender_separated', 'has_wheelchair_access', 'has_changing_table', 'accessible_outside_opening_times', 'public_accessible'];
        foreach ($flagFields as $field) {
            if (array_key_exists($field, $input)) {
                $this->setFlag($toilet->id, $field, (bool) $input[$field], $diff);
            }
        }

        $propFields = ['address', 'comment', 'website', 'euro_key', 'storage_space'];
        foreach ($propFields as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $this->setProperty($toilet->id, $field, $input[$field], $diff);
        }

        if (array_key_exists('place_opening_hours', $input)) {
            $periods = is_array($input['place_opening_hours'])
                ? PlaceToiletService::normalizePeriodPoints($input['place_opening_hours'])
                : null;
            $this->setProperty(
                $toilet->id,
                'place_opening_hours',
                $periods !== null && count($periods) > 0 ? json_encode($periods) : null,
                $diff
            );
        }

        if (count($diff) > 0) {
            $toilet->update([
                'email_sent' => 2,
                'last_diff' => json_encode($diff),
            ]);
        }

        return response()->json([
            'success' => true,
            'id' => $toilet->id,
            'diff' => $diff,
        ]);
    }

    /**
     * Add a new toilet.
     */
    public function add(StoreToiletRequest $request): JsonResponse
    {
        $input = $request->validated();
        $diff = [];
        $userOverriddenFields = [];

        $toilet = Toilet::create([
            'name' => $input['name'] ?? 'Toilette',
            'owner' => $input['owner'] ?? '',
            'lat' => $input['lat'] ?? null,
            'lon' => $input['lon'] ?? null,
            'place_id' => $input['place_id'] ?? null,
            'status' => $input['status'] ?? 'active',
            'source' => $input['source'] ?? null,
        ]);

        if (($input['name'] ?? 'Toilette') === 'Toilette') {
            $toilet->update(['name' => 'WC #'.$toilet->id]);
        } else {
            $userOverriddenFields[] = 'name';
        }

        if (! empty($input['owner'])) {
            $userOverriddenFields[] = 'owner';
        }

        if (isset($input['source']) && $input['source'] !== null) {
            $userOverriddenFields[] = 'source';
        }

        if (isset($input['lat']) && $input['lat'] !== null) {
            $userOverriddenFields[] = 'lat';
        }

        if (isset($input['lon']) && $input['lon'] !== null) {
            $userOverriddenFields[] = 'lon';
        }

        if (isset($input['status'])) {
            $userOverriddenFields[] = 'status';
        }

        if (isset($input['place_id'])) {
            $userOverriddenFields[] = 'place_id';
        }

        if (! empty($userOverriddenFields)) {
            $toilet->markUserOverridden(...$userOverriddenFields);
        }

        $flagFields = ['is_unisex', 'is_gender_separated', 'has_wheelchair_access', 'has_changing_table', 'accessible_outside_opening_times', 'public_accessible'];
        foreach ($flagFields as $field) {
            if (isset($input[$field]) && $input[$field]) {
                $this->setFlag($toilet->id, $field, true, $diff);
            }
        }

        $propFields = ['address', 'comment', 'website', 'euro_key', 'storage_space'];
        foreach ($propFields as $field) {
            if (! empty($input[$field])) {
                $this->setProperty($toilet->id, $field, $input[$field], $diff);
            }
        }

        if (! empty($input['place_opening_hours']) && is_array($input['place_opening_hours'])) {
            $periods = PlaceToiletService::normalizePeriodPoints($input['place_opening_hours']);
            if (count($periods) > 0) {
                $this->setProperty($toilet->id, 'place_opening_hours', json_encode($periods), $diff);
            }
        }

        if (! empty($input['place_id'])) {
            $this->checkAndUpdatePlaceType($input['place_id']);
        }

        return response()->json([
            'success' => true,
            'id' => $toilet->id,
        ]);
    }

    /**
     * Add properties to a toilet.
     */
    public function addProperties(int $toiletId, StoreToiletPropertiesRequest $request): JsonResponse
    {
        Toilet::findOrFail($toiletId);
        $rows = $request->validated();

        foreach ($rows as $row) {
            $value = $row['value'];

            if ($row['type'] === 'place_opening_hours') {
                if (is_array($value)) {
                    $value = json_encode(PlaceToiletService::normalizePeriodPoints($value));
                } elseif (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $value = json_encode(PlaceToiletService::normalizePeriodPoints($decoded));
                    }
                }
            }

            DB::table('toilet_properties')->updateOrInsert(
                ['fk_toiletId' => $toiletId, 'type' => $row['type']],
                ['value' => $value, 'user_overridden' => 1]
            );
        }

        return response()->json([
            'success' => true,
            'count' => count($rows),
        ]);
    }

    /**
     * Admin action: mark a toilet as qualified.
     */
    public function adminQualify(int $id, Request $request): Response
    {
        return $this->adminAction($id, $request, function (Toilet $toilet) {
            $toilet->update(['is_qualified' => 1]);

            return response()->json(
                'Successfully qualified Toilet '.$toilet->id.'<a href="https://wc-info.de/Toilets/xyz---'.$toilet->place_id.'/xyz-'.$toilet->id.'">To the toilet</a>'
            );
        }, 'qualify');
    }

    /**
     * Admin action: delete a toilet.
     */
    public function adminDelete(int $id, Request $request): Response
    {
        return $this->adminAction($id, $request, function (Toilet $toilet) {
            $toilet->update(['status' => 'deleted']);

            return response()->json(
                'Successfully deleted Toilet '.$toilet->id.'<a href="https://wc-info.de/Toilets/xyz---'.$toilet->place_id.'/xyz-'.$toilet->id.'">To the toilet</a>'
            );
        }, 'delete');
    }

    private function adminAction(int $id, Request $request, callable $action, string $verb)
    {
        $hash = $request->input('hash');
        $confirmed = $request->input('confirmed', '0');

        $toilet = Toilet::findOrFail($id);

        if (! $this->adminLink->verify($toilet->id, $toilet->place_id, $hash)) {
            abort(403, 'invalid hash');
        }

        if ($confirmed !== '1') {
            return response(
                'Really <strong>'.$verb.'</strong> Toilet '.$toilet->id.' / '.$toilet->name.' / '.$toilet->owner.'? <a href="'.$request->fullUrl().'&confirmed=1">YES</a>'
            );
        }

        return $action($toilet);
    }

    private function setFlag(int $toiletId, string $flag, bool $value, array &$diff): void
    {
        $existing = DB::table('toilet_properties')
            ->where('fk_toiletId', $toiletId)
            ->where('type', $flag)
            ->first();

        $targetValue = $value ? '1' : '0';

        if (! $existing || $existing->value !== $targetValue) {
            $diff[$flag] = ['old' => $existing?->value ?: '0', 'new' => $targetValue];
        }

        DB::table('toilet_properties')->updateOrInsert(
            ['fk_toiletId' => $toiletId, 'type' => $flag],
            ['value' => $targetValue, 'user_overridden' => 1]
        );
    }

    private function setProperty(int $toiletId, string $type, ?string $value, array &$diff): void
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

    private function checkAndUpdatePlaceType(string $placeId): void
    {
        if (TypeXPlace::where('place_id', $placeId)->exists()) {
            return;
        }

        $details = $this->placesService->fetchPlaceDetails($placeId);

        if (empty($details['types']) || ! is_array($details['types'])) {
            return;
        }

        foreach ($details['types'] as $typeName) {
            $type = Type::firstOrCreate(['type' => $typeName]);

            TypeXPlace::insertOrIgnore([
                'type_id' => $type->id,
                'place_id' => $placeId,
            ]);
        }
    }
}
