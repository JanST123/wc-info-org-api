<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendToiletFeedbackRequest;
use App\Http\Requests\StoreToiletPropertiesRequest;
use App\Http\Requests\StoreToiletRequest;
use App\Http\Requests\UpdateToiletRequest;
use App\Http\Resources\ToiletDetailResource;
use App\Http\Resources\ToiletListResource;
use App\Models\Toilet;
use App\Models\ToiletProperty;
use App\Models\Type;
use App\Models\TypeXPlace;
use App\Services\GooglePlacesService;
use App\Services\MailService;
use App\Services\OpeningHoursService;
use App\Services\PlaceToiletService;
use App\Services\ToiletRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ToiletController extends Controller
{
    public function __construct(
        private GooglePlacesService $placesService,
        private ToiletRevisionService $revisionService,
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
     * @queryParam distance float Distance buffer around the bounding box in kilometers (default: 4, min: 0.1, max: 10). Example: 4
     * @queryParam filter string Comma-separated key:value attribute filters (e.g. is_open:true,euro_key:yes). Example: is_open:true
     */
    public function forBounds(Request $request, float $south, float $west, float $north, float $east): JsonResponse
    {
        $validated = $request->validate([
            'distance' => ['sometimes', 'numeric', 'min:0.1', 'max:10'],
            'filter' => ['sometimes', 'string'],
        ]);

        $distance = (float) ($validated['distance'] ?? 4);
        $filter = $this->parseFilter($validated['filter'] ?? null);

        $minLon = min($west, $east);
        $maxLon = max($west, $east);
        $minLat = min($south, $north);
        $maxLat = max($south, $north);

        $areaKm2 = $this->calculateBoundsAreaKm2($minLat, $minLon, $maxLat, $maxLon);
        $thresholdKm2 = (float) config('wcinfo.bounds_summary_threshold_km2', 50.0);

        if ($areaKm2 > $thresholdKm2) {
            return $this->forBoundsSummary($minLat, $maxLat, $minLon, $maxLon, $filter, $areaKm2);
        }

        $earthRadius = 6371.0;
        $dLat = rad2deg($distance / $earthRadius);
        $expandedMinLat = max(-90.0, $minLat - $dLat);
        $expandedMaxLat = min(90.0, $maxLat + $dLat);

        $maxAbsLat = max(abs($expandedMinLat), abs($expandedMaxLat));
        $cosLat = cos(deg2rad($maxAbsLat));
        $dLon = $cosLat > 0.0001 ? rad2deg($distance / ($earthRadius * $cosLat)) : 180.0;
        $expandedMinLon = max(-180.0, $minLon - $dLon);
        $expandedMaxLon = min(180.0, $maxLon + $dLon);

        $maxLimit = (int) config('wcinfo.bounds_max_unsummarized_limit', 500);

        $toilets = Toilet::visible()
            ->whereBetween('lon', [$expandedMinLon, $expandedMaxLon])
            ->whereBetween('lat', [$expandedMinLat, $expandedMaxLat])
            ->with(['properties', 'photos'])
            ->limit($maxLimit)
            ->get();

        if ($toilets->isEmpty()) {
            $centerLat = ($minLat + $maxLat) / 2;
            $centerLon = ($minLon + $maxLon) / 2;
            $searchDistance = $this->haversineDistance($centerLat, $centerLon, $expandedMaxLat, $expandedMaxLon);
            $searchDistance = max(0.1, min($searchDistance, 10.0));

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
     * @queryParam distance float Search radius in kilometers (default: 4, min: 0.1, max: 10). Example: 4
     * @queryParam filter string Comma-separated key:value attribute filters (e.g. is_open:true,euro_key:yes). Example: is_open:true
     */
    public function nearby(Request $request, float $lat, float $lon): JsonResponse
    {
        $validated = $request->validate([
            'distance' => ['sometimes', 'numeric', 'min:0.1', 'max:10'],
            'filter' => ['sometimes', 'string'],
        ]);

        $distance = (float) ($validated['distance'] ?? 4);
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

    public function calculateBoundsAreaKm2(float $south, float $west, float $north, float $east): float
    {
        $minLat = min($south, $north);
        $maxLat = max($south, $north);
        $minLon = min($west, $east);
        $maxLon = max($west, $east);

        $heightKm = ($maxLat - $minLat) * 111.32;
        $midLat = ($minLat + $maxLat) / 2.0;
        $cosMid = max(0.0001, cos(deg2rad($midLat)));
        $widthKm = ($maxLon - $minLon) * 111.32 * $cosMid;

        return round($heightKm * $widthKm, 2);
    }

    public function extractCityFromAddress(string $address): ?string
    {
        $cleaned = preg_replace('/,\s*(Deutschland|Germany|Österreich|Austria|Schweiz|Switzerland|Belgien|Belgium|Niederlande|Netherlands|France|Frankreich)\s*$/iu', '', trim($address));

        if (preg_match('/(?:^|[,\s])(?:\b[A-Z]{1,2}-)?\d{4,5}\s+([A-Za-zÄÖÜäöüß\s\-\.\/]+?)(?:,|$)/u', $cleaned, $matches)) {
            $city = trim(preg_split('/[,]/', $matches[1])[0]);
            if ($city !== '') {
                return $city;
            }
        }

        $parts = array_map('trim', explode(',', $cleaned));
        if (count($parts) > 1) {
            $last = end($parts);
            $lastClean = trim(preg_replace('/^\d+\s*/', '', $last));
            if ($lastClean !== '') {
                return $lastClean;
            }
        }

        return null;
    }

    public function getCellSizeKm2ForArea(float $areaKm2): float
    {
        $tiers = config('wcinfo.bounds_summary_cell_tiers');

        if (is_array($tiers)) {
            foreach ($tiers as $tier) {
                if (isset($tier[0], $tier[1]) && $areaKm2 <= (float) $tier[0]) {
                    return (float) $tier[1];
                }
            }
        }

        return (float) config('wcinfo.bounds_summary_cell_size_km2', 8.0);
    }

    private function forBoundsSummary(float $minLat, float $maxLat, float $minLon, float $maxLon, array $filter, float $areaKm2): JsonResponse
    {
        $cellAreaKm2 = $this->getCellSizeKm2ForArea($areaKm2);
        $sideKm = sqrt(max(0.1, $cellAreaKm2));

        $dLat = $sideKm / 111.32;
        $midLat = ($minLat + $maxLat) / 2.0;
        $cosMid = max(0.0001, cos(deg2rad($midLat)));
        $dLon = $sideKm / (111.32 * $cosMid);

        $query = DB::table('toilets')
            ->leftJoin('toilet_properties AS tp_wheelchair', function ($join) {
                $join->on('tp_wheelchair.fk_toiletId', '=', 'toilets.id')
                    ->where('tp_wheelchair.type', '=', 'has_wheelchair_access')
                    ->where('tp_wheelchair.value', '=', '1');
            })
            ->leftJoin('toilet_properties AS tp_address', function ($join) {
                $join->on('tp_address.fk_toiletId', '=', 'toilets.id')
                    ->where('tp_address.type', '=', 'address')
                    ->whereNotNull('tp_address.value')
                    ->where('tp_address.value', '!=', '');
            })
            ->where('toilets.status', 'active')
            ->whereBetween('toilets.lat', [$minLat, $maxLat])
            ->whereBetween('toilets.lon', [$minLon, $maxLon]);

        if (isset($filter['has_wheelchair_access'])) {
            $isWheelchair = in_array($filter['has_wheelchair_access'], ['1', 'true', 'yes'], true);
            if ($isWheelchair) {
                $query->whereNotNull('tp_wheelchair.fk_toiletId');
            } else {
                $query->whereNull('tp_wheelchair.fk_toiletId');
            }
        }

        $cells = $query->selectRaw("
                FLOOR(toilets.lat / ?) AS lat_cell,
                FLOOR(toilets.lon / ?) AS lon_cell,
                COUNT(DISTINCT toilets.id) AS summary_count,
                MAX(CASE WHEN tp_wheelchair.fk_toiletId IS NOT NULL THEN 1 ELSE 0 END) AS has_wheelchair_access,
                MAX(tp_address.value) AS sample_address
            ", [$dLat, $dLon])
            ->groupBy('lat_cell', 'lon_cell')
            ->get();

        $summaries = collect();

        foreach ($cells as $cell) {
            $latCell = (int) $cell->lat_cell;
            $lonCell = (int) $cell->lon_cell;

            $cellSouth = round($latCell * $dLat, 6);
            $cellNorth = round(($latCell + 1) * $dLat, 6);
            $cellWest = round($lonCell * $dLon, 6);
            $cellEast = round(($lonCell + 1) * $dLon, 6);

            $centerLat = round(($latCell + 0.5) * $dLat, 6);
            $centerLon = round(($lonCell + 0.5) * $dLon, 6);

            $normLat = $latCell + 10000;
            $normLon = $lonCell + 20000;
            $syntheticId = -abs(($normLat * 100000) + $normLon);

            $city = ! empty($cell->sample_address) ? $this->extractCityFromAddress((string) $cell->sample_address) : null;

            if ($city === null) {
                $cached = DB::table('google_nearby_search_cache')
                    ->whereBetween('lat', [$cellSouth, $cellNorth])
                    ->whereBetween('lon', [$cellWest, $cellEast])
                    ->first();

                if ($cached !== null && ! empty($cached->response_places)) {
                    $places = is_array($cached->response_places)
                        ? $cached->response_places
                        : json_decode((string) $cached->response_places, true);

                    if (is_array($places)) {
                        foreach ($places as $place) {
                            if (! empty($place['formattedAddress'])) {
                                $city = $this->extractCityFromAddress((string) $place['formattedAddress']);
                                if ($city !== null) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            $locationLabel = $city ?? (round($centerLat, 2).', '.round($centerLon, 2));
            $count = (int) $cell->summary_count;
            $toiletWord = $count === 1 ? 'Toilet' : 'Toilets';
            $summaryName = "{$count} {$toiletWord} around {$locationLabel}";

            $syntheticToilet = new Toilet([
                'name' => $summaryName,
                'owner' => $summaryName,
                'lat' => $centerLat,
                'lon' => $centerLon,
                'place_id' => null,
                'status' => 'active',
                'is_qualified' => true,
            ]);
            $syntheticToilet->id = $syntheticId;

            $props = collect();
            if ((int) $cell->has_wheelchair_access === 1) {
                $props->push(new ToiletProperty(['type' => 'has_wheelchair_access', 'value' => '1']));
            }
            $props->push(new ToiletProperty(['type' => 'public_accessible', 'value' => '1']));

            $syntheticToilet->setRelation('properties', $props);
            $syntheticToilet->setRelation('photos', collect([]));

            $summaryBounds = [
                'south' => $cellSouth,
                'west' => $cellWest,
                'north' => $cellNorth,
                'east' => $cellEast,
            ];

            $syntheticToilet->setAttribute('is_summary', true);
            $syntheticToilet->setAttribute('summary_count', (int) $cell->summary_count);
            $syntheticToilet->setAttribute('summary_bounds', $summaryBounds);
            $syntheticToilet->setAttribute('is_open', true);
            $syntheticToilet->setAttribute('open_timestamp', null);
            $syntheticToilet->setAttribute('close_timestamp', null);

            $summaries->push($syntheticToilet);
        }

        return response()->json(ToiletListResource::collection($summaries));
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
                    $isTemporaryClosed = $toilet->isFlagSet('temporary_closed');
                    $isAccessibleOutside = $toilet->isFlagSet('accessible_outside_opening_times');
                    $hasNoOpeningTimes = ! $hasPeriods;
                    $matchesOpen = ! $isTemporaryClosed && ($state['is_open'] || $isAccessibleOutside || $hasNoOpeningTimes);

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
            $newLat = round((float) $input['lat'], 7);
            if (! Toilet::areCoordinatesEqual($toilet->lat, $newLat)) {
                $diff['lat'] = ['old' => $toilet->lat, 'new' => $newLat];
                $toilet->lat = $newLat;
                $userOverriddenFields[] = 'lat';
            }
        }

        if (array_key_exists('lon', $input) && $input['lon'] !== null) {
            $newLon = round((float) $input['lon'], 7);
            if (! Toilet::areCoordinatesEqual($toilet->lon, $newLon)) {
                $diff['lon'] = ['old' => $toilet->lon, 'new' => $newLon];
                $toilet->lon = $newLon;
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

        $flagFields = ['is_unisex', 'is_gender_separated', 'has_wheelchair_access', 'has_changing_table', 'accessible_outside_opening_times', 'public_accessible', 'temporary_closed'];
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
                'email_sent' => $toilet->email_sent === 0 ? 0 : 2, // if its a new toilet keep email_sent as 0, otherwise set to 2 (needs re-notification)
                'last_diff' => json_encode($diff),
            ]);
            $this->revisionService->recordRevision($toilet, 'api_patch', $diff);
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

        $flagFields = ['is_unisex', 'is_gender_separated', 'has_wheelchair_access', 'has_changing_table', 'accessible_outside_opening_times', 'public_accessible', 'temporary_closed'];
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

        $this->revisionService->recordRevision($toilet, 'api_add', null, 'Initial toilet creation');

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
        $toilet = Toilet::findOrFail($toiletId);
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

        $this->revisionService->recordRevision($toilet, 'add_properties', null, 'Added properties');

        return response()->json([
            'success' => true,
            'count' => count($rows),
        ]);
    }

    /**
     * Send feedback email for a toilet.
     */
    public function feedback(int $toiletId, SendToiletFeedbackRequest $request, MailService $mailService): JsonResponse
    {
        $toilet = Toilet::with(['place', 'latestRevision'])->find($toiletId);

        if (! $toilet) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'Toilet not found',
            ], 404);
        }

        $input = $request->validated();
        $userSubject = $input['subject'];
        $userMessage = isset($input['message']) && is_string($input['message']) ? trim($input['message']) : '';

        $toEmail = (string) config('wcinfo.sender_mail');
        $emailSubject = 'Feedback: '.$userSubject;

        $placeName = $toilet->place?->getName() ?: '-';
        $lastUpdateTimestamp = $toilet->latestRevision?->created_at
            ?? $toilet->updated
            ?? $toilet->created_at
            ?? $toilet->last_discovered
            ?? $toilet->last_included;
        $lastUpdate = $lastUpdateTimestamp ? $lastUpdateTimestamp->format('Y-m-d H:i:s') : '-';

        $adminUrl = url("/admin/toilets/{$toilet->id}");

        $body = "<h2>Feedback Received</h2>\n";
        if ($userMessage !== '') {
            $body .= "<p><strong>Message:</strong></p>\n"
                .'<blockquote>'.nl2br(e($userMessage))."</blockquote>\n";
        }
        $body .= "<hr>\n"
            ."<h3>Toilet Details</h3>\n"
            ."<ul>\n"
            ."<li><strong>ID:</strong> {$toilet->id}</li>\n"
            .'<li><strong>Name:</strong> '.e($toilet->name ?? '-')."</li>\n"
            .'<li><strong>Owner:</strong> '.e($toilet->owner ?: '-')."</li>\n"
            .'<li><strong>Place Name:</strong> '.e($placeName)."</li>\n"
            .'<li><strong>Last Update:</strong> '.e($lastUpdate)."</li>\n"
            ."</ul>\n"
            .'<p><a href="'.e($adminUrl).'">Edit Toilet in Admin Panel</a></p>';

        $mailService->send($toEmail, $emailSubject, $body, true);

        return response()->json([
            'status' => 'okay',
            'message' => 'Feedback sent successfully',
        ]);
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
                ['value' => '', 'user_overridden' => 0]
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
