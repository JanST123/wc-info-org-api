<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Toilet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GooglePlacesService
{
    public const EXCLUDED_NEARBY_TYPES = [
        'lodging',
        'post_office',
        'shipping_service',
        'atm',
    ];

    private string $apiKey;

    private GoogleCostService $costService;

    private GoogleNearbyCacheService $cacheService;

    public function __construct(
        ?GoogleCostService $costService = null,
        ?GoogleNearbyCacheService $cacheService = null,
    ) {
        $this->apiKey = (string) config('wcinfo.google.api_key');
        $this->costService = $costService ?? app(GoogleCostService::class);
        $this->cacheService = $cacheService ?? app(GoogleNearbyCacheService::class);

        if (empty($this->apiKey)) {
            throw new RuntimeException('Google API key is not configured.');
        }
    }

    public function fetchPlaceDetails(string $placeId): ?array
    {
        if (! $this->costService->hasBudget()) {
            Log::warning('Google API call blocked: Monthly budget exceeded', [
                'service' => GoogleCostService::SERVICE_PLACES_DETAILS,
                'place_id' => $placeId,
            ]);
            $this->costService->checkBudgetAndNotify();

            return null;
        }

        $endpoint = "https://places.googleapis.com/v1/places/{$placeId}";
        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'id,displayName,location,regularOpeningHours,websiteUri,formattedAddress,types,business_status,accessibilityOptions,restroom',
        ])->get($endpoint, [
            'languageCode' => 'de',
        ]);

        $this->costService->logApiCall(
            GoogleCostService::SERVICE_PLACES_DETAILS,
            $endpoint,
            GoogleCostService::COST_PLACES_DETAILS_USD,
            $response->status(),
            ['place_id' => $placeId]
        );

        if ($response->failed()) {
            Log::warning('Google Places Details request failed', [
                'place_id' => $placeId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $json = $response->json();

        return ! empty($json['id']) ? $json : null;
    }

    public function placesForCoordinates(float $lat, float $lon): array
    {
        if (! $this->costService->hasBudget()) {
            Log::warning('Google API call blocked: Monthly budget exceeded', [
                'service' => GoogleCostService::SERVICE_GEOCODING,
                'lat' => $lat,
                'lon' => $lon,
            ]);
            $this->costService->checkBudgetAndNotify();

            return [];
        }

        $endpoint = 'https://maps.googleapis.com/maps/api/geocode/json';
        $response = Http::get($endpoint, [
            'latlng' => $lat.','.$lon,
            'key' => $this->apiKey,
        ]);

        $this->costService->logApiCall(
            GoogleCostService::SERVICE_GEOCODING,
            $endpoint,
            GoogleCostService::COST_GEOCODING_USD,
            $response->status(),
            ['lat' => $lat, 'lon' => $lon]
        );

        if ($response->failed()) {
            Log::warning('Google Geocoding request failed', ['lat' => $lat, 'lon' => $lon]);

            return [];
        }

        $json = $response->json();

        return $json['status'] === 'OK' ? $json['results'] : [];
    }

    public function crawlWebsite(string $url, string $term): array
    {
        if (! $this->costService->hasBudget()) {
            Log::warning('Google API call blocked: Monthly budget exceeded', [
                'service' => GoogleCostService::SERVICE_CUSTOM_SEARCH,
                'url' => $url,
            ]);
            $this->costService->checkBudgetAndNotify();

            return [
                'toiletType' => 'none',
                'contactEmail' => '',
                'resultCount' => 0,
            ];
        }

        Log::info('Starting website crawl', ['url' => $url, 'term' => $term]);

        if (! preg_match('/^https?:\/\/[a-z0-9öäüß_.-]+\.(de|com|net|eu|org|info)/', $url, $matches)) {
            Log::warning('Website crawl rejected: unsupported URL pattern', ['url' => $url]);
            throw new RuntimeException('Unsupported url pattern: '.$url);
        }

        $tld = $matches[1];
        $cx = $this->resolveSearchEngineId($tld);

        Log::info('Fetching Google Custom Search results', [
            'url' => $url,
            'term' => $term,
            'tld' => $tld,
            'search_engine_id' => $cx,
        ]);

        $endpoint = 'https://customsearch.googleapis.com/customsearch/v1';
        $response = Http::get($endpoint, [
            'cx' => $cx,
            'exactTerms' => $term,
            'siteSearch' => $url,
            'key' => $this->apiKey,
        ]);

        $this->costService->logApiCall(
            GoogleCostService::SERVICE_CUSTOM_SEARCH,
            $endpoint,
            GoogleCostService::COST_CUSTOM_SEARCH_USD,
            $response->status(),
            ['url' => $url, 'term' => $term]
        );

        if ($response->failed()) {
            Log::warning('Google Custom Search request failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Google Custom Search request failed');
        }

        $json = $response->json();
        $items = $json['items'] ?? [];
        $resultCount = count($items);

        Log::info('Google Custom Search response received', [
            'url' => $url,
            'result_count' => $resultCount,
            'search_status' => $json['status'] ?? 'unknown',
        ]);

        $toiletType = 'none';
        $contactEmail = '';

        foreach ($items as $index => $item) {
            $snippet = $item['snippet'] ?? '';
            $title = $item['title'] ?? '';
            $link = $item['link'] ?? '';

            Log::debug('Crawl result item', [
                'url' => $url,
                'index' => $index,
                'title' => $title,
                'link' => $link,
                'snippet' => $snippet,
            ]);

            if (empty($contactEmail) && preg_match("/([a-z0-9!#$%&'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)/", $snippet, $emailMatches)) {
                $contactEmail = $emailMatches[1];
                Log::info('Contact email found in crawl', ['url' => $url, 'email' => $contactEmail]);
            }

            if (preg_match('/\b(toilet|wc|klo|restroom)/i', $snippet)) {
                $toiletType = 'forall';
            }
        }

        foreach ($items as $item) {
            $snippet = strtolower($item['snippet'] ?? '');

            if (preg_match('/\b(dame|herr|man|men|woman|women|getrennt)/i', $snippet)
                || str_contains($snippet, 'm/w')
                || str_contains($snippet, 'm|w')) {
                $toiletType = 'mw';
            }
        }

        foreach ($items as $item) {
            $snippet = strtolower($item['snippet'] ?? '');

            if (preg_match('/\b(barrieref|behindert|rollstuhl)/i', $snippet)) {
                if ($toiletType === 'forall') {
                    $toiletType = 'mw';
                }
                $toiletType .= 'd';
                break;
            }
        }

        foreach ($items as $item) {
            $snippet = strtolower($item['snippet'] ?? '');

            if (preg_match('/\b(wickel|mutter|changing)/i', $snippet)) {
                if ($toiletType === 'forall') {
                    $toiletType = 'mw';
                }
                $toiletType .= 'b';
                break;
            }
        }

        Log::info('Website crawl finished', [
            'url' => $url,
            'result_count' => $resultCount,
            'toilet_type' => $toiletType,
            'contact_email' => $contactEmail,
        ]);

        return [
            'toiletType' => $toiletType,
            'contactEmail' => $contactEmail,
            'resultCount' => $resultCount,
        ];
    }

    /**
     * Fetch raw results from Google Places Nearby Search.
     *
     * Checks intelligent geometric spatial cache before calling the Google Places API.
     * If a fresh (default <=30 days) cached search circle fully encloses the requested
     * query circle, results are served directly from cache without incurring API costs.
     *
     * @return array<int, array>
     */
    public function nearbySearchRaw(float $lat, float $lon, float $distanceKm, bool $forceRefresh = false): array
    {
        $radius = (float) min(round($distanceKm * 1000, 1), 50000);
        $freshnessDays = (int) config('wcinfo.google.nearby_cache_days', 30);
        $endpoint = 'https://places.googleapis.com/v1/places:searchNearby';

        // 1. Check intelligent geometric spatial cache
        if (! $forceRefresh) {
            $cached = $this->cacheService->findEnclosingCache($lat, $lon, $radius, $freshnessDays);

            if ($cached !== null) {
                $filteredPlaces = $this->cacheService->filterCachedPlaces(
                    $cached->response_places ?? [],
                    $lat,
                    $lon,
                    $radius,
                    10
                );

                // Log cache hit ($0.00 cost, is_cache_hit = true)
                $this->costService->logApiCall(
                    GoogleCostService::SERVICE_PLACES_NEARBY,
                    $endpoint,
                    0.0,
                    200,
                    [
                        'lat' => $lat,
                        'lon' => $lon,
                        'distance_km' => $distanceKm,
                        'cached' => true,
                        'cache_id' => $cached->id,
                        'cached_radius_m' => $cached->radius_meters,
                        'results_count' => count($filteredPlaces),
                    ],
                    true
                );

                return $filteredPlaces;
            }
        }

        // 2. Cache miss -> check monthly budget before external API call
        if (! $this->costService->hasBudget()) {
            Log::warning('Google API call blocked: Monthly budget exceeded', [
                'service' => GoogleCostService::SERVICE_PLACES_NEARBY,
                'lat' => $lat,
                'lon' => $lon,
            ]);
            $this->costService->checkBudgetAndNotify();

            return [];
        }

        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.location,places.regularOpeningHours,places.websiteUri,places.formattedAddress,places.types,places.business_status,places.accessibilityOptions,places.restroom',
        ])->post($endpoint, [
            'languageCode' => 'de',
            'locationRestriction' => [
                'circle' => [
                    'center' => [
                        'latitude' => $lat,
                        'longitude' => $lon,
                    ],
                    'radius' => $radius,
                ],
            ],
            'excludedTypes' => self::EXCLUDED_NEARBY_TYPES,
            'rankPreference' => 'DISTANCE',
            'maxResultCount' => 20,
        ]);

        $this->costService->logApiCall(
            GoogleCostService::SERVICE_PLACES_NEARBY,
            $endpoint,
            GoogleCostService::COST_PLACES_NEARBY_USD,
            $response->status(),
            ['lat' => $lat, 'lon' => $lon, 'distance_km' => $distanceKm, 'cached' => false],
            false
        );

        if ($response->failed()) {
            Log::warning('Google Nearby Search request failed', [
                'lat' => $lat,
                'lon' => $lon,
                'radius' => $radius,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $json = $response->json();
        $places = $json['places'] ?? [];

        // 3. Store result in cache table
        $this->cacheService->store($lat, $lon, $radius, $places, [
            'languageCode' => 'de',
            'excludedTypes' => self::EXCLUDED_NEARBY_TYPES,
            'rankPreference' => 'DISTANCE',
            'maxResultCount' => 20,
        ]);

        return $places;
    }

    /**
     * Find nearest Google places to a coordinate within a radius (in meters), preferring cached data
     * from google_nearby_search_cache (freshness <= 30 days) or fetching from Google Places API (New),
     * and ordering results by type priorize flag (1 = preferred) and distance ascending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNearestPlaces(
        float $lat,
        float $lon,
        float $radiusMeters = 40.0,
        int $limit = 3,
        bool $forceRefresh = false
    ): array {
        $radius = max(1.0, min($radiusMeters, 50000.0));
        $freshnessDays = (int) config('wcinfo.google.nearby_cache_days', 30);
        $endpoint = 'https://places.googleapis.com/v1/places:searchNearby';
        $places = [];

        // 1. Check intelligent geometric spatial cache
        if (! $forceRefresh) {
            $cached = $this->cacheService->findEnclosingCache($lat, $lon, $radius, $freshnessDays);

            if ($cached !== null) {
                $cachedPlaces = $this->cacheService->filterCachedPlaces(
                    $cached->response_places ?? [],
                    $lat,
                    $lon,
                    $radius
                );

                if (! empty($cachedPlaces)) {
                    $this->costService->logApiCall(
                        GoogleCostService::SERVICE_PLACES_NEARBY,
                        $endpoint,
                        0.0,
                        200,
                        [
                            'lat' => $lat,
                            'lon' => $lon,
                            'radius_meters' => $radius,
                            'cached' => true,
                            'cache_id' => $cached->id,
                            'results_count' => count($cachedPlaces),
                        ],
                        true
                    );

                    $places = $cachedPlaces;
                }
            }
        }

        // 2. Cache miss or empty cached results -> call Google Places API
        if (empty($places)) {
            if (! $this->costService->hasBudget()) {
                Log::warning('Google API call blocked: Monthly budget exceeded', [
                    'service' => GoogleCostService::SERVICE_PLACES_NEARBY,
                    'lat' => $lat,
                    'lon' => $lon,
                    'radius_meters' => $radius,
                ]);
                $this->costService->checkBudgetAndNotify();
            } else {
                $response = Http::withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => 'places.id,places.displayName,places.location,places.regularOpeningHours,places.websiteUri,places.formattedAddress,places.types,places.business_status,places.accessibilityOptions,places.restroom',
                ])->post($endpoint, [
                    'languageCode' => 'de',
                    'locationRestriction' => [
                        'circle' => [
                            'center' => [
                                'latitude' => $lat,
                                'longitude' => $lon,
                            ],
                            'radius' => max(10.0, $radius),
                        ],
                    ],
                    'excludedTypes' => self::EXCLUDED_NEARBY_TYPES,
                    'rankPreference' => 'DISTANCE',
                    'maxResultCount' => 20,
                ]);

                $this->costService->logApiCall(
                    GoogleCostService::SERVICE_PLACES_NEARBY,
                    $endpoint,
                    GoogleCostService::COST_PLACES_NEARBY_USD,
                    $response->status(),
                    ['lat' => $lat, 'lon' => $lon, 'radius_meters' => $radius, 'cached' => false],
                    false
                );

                if ($response->successful()) {
                    $json = $response->json();
                    $rawPlaces = $json['places'] ?? [];

                    // Store result in cache table
                    $this->cacheService->store($lat, $lon, max(10.0, $radius), $rawPlaces, [
                        'languageCode' => 'de',
                        'excludedTypes' => self::EXCLUDED_NEARBY_TYPES,
                        'rankPreference' => 'DISTANCE',
                        'maxResultCount' => 20,
                    ]);

                    // Persist places in places table
                    foreach ($rawPlaces as $rawPlace) {
                        $placeId = $rawPlace['id'] ?? $rawPlace['place_id'] ?? null;
                        if ($placeId) {
                            Place::updateOrCreate(
                                ['place_id' => $placeId],
                                ['data' => $rawPlace]
                            );
                        }
                    }

                    $places = $this->cacheService->filterCachedPlaces($rawPlaces, $lat, $lon, $radius);
                } else {
                    Log::warning('Google Nearby Search request failed in getNearestPlaces', [
                        'lat' => $lat,
                        'lon' => $lon,
                        'radius_meters' => $radius,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            }
        }

        // 3. Order results by distance and priorize flag (1 = prefer)
        $prioritizedTypeNames = DB::table('types')
            ->where('priorize', 1)
            ->pluck('type')
            ->all();
        $prioritizedMap = array_fill_keys($prioritizedTypeNames, true);

        $placesWithScore = [];
        foreach ($places as $place) {
            $coords = $this->cacheService->extractPlaceCoordinates($place);
            $dist = $coords !== null
                ? $this->cacheService->haversineDistanceMeters($lat, $lon, $coords['lat'], $coords['lon'])
                : 999999.0;

            // Only keep places within the requested radius (with 1.0m tolerance)
            if ($dist > ($radius + 1.0)) {
                continue;
            }

            $types = (array) ($place['types'] ?? []);
            if (! empty(array_intersect($types, self::EXCLUDED_NEARBY_TYPES))) {
                continue;
            }

            $priorize = 0;
            if (in_array('public_bathroom', $types, true)) {
                $priorize = 2;
            } else {
                foreach ($types as $type) {
                    if (isset($prioritizedMap[$type])) {
                        $priorize = 1;
                        break;
                    }
                }
            }

            $placesWithScore[] = [
                'distance' => $dist,
                'priorize' => $priorize,
                'place' => $place,
            ];
        }

        // Sort by priorize DESC (1 before 0), then distance ASC (closer first)
        usort($placesWithScore, function (array $a, array $b): int {
            if ($a['priorize'] !== $b['priorize']) {
                return $b['priorize'] <=> $a['priorize'];
            }

            return $a['distance'] <=> $b['distance'];
        });

        if ($limit > 0) {
            $placesWithScore = array_slice($placesWithScore, 0, $limit);
        }

        return array_map(function (array $item): array {
            $place = $item['place'];
            $place['distance'] = round($item['distance'], 1);

            return $place;
        }, $placesWithScore);
    }

    /**
     * Fetch raw results from Google Places Text Search (New).
     *
     * @return array<int, array>
     */
    public function textSearchRaw(string $query, ?float $lat = null, ?float $lon = null, float $radiusMeters = 5000.0): array
    {
        if (! $this->costService->hasBudget()) {
            Log::warning('Google API call blocked: Monthly budget exceeded', [
                'service' => GoogleCostService::SERVICE_PLACES_TEXT_SEARCH,
                'query' => $query,
            ]);
            $this->costService->checkBudgetAndNotify();

            return [];
        }

        $endpoint = 'https://places.googleapis.com/v1/places:searchText';

        $payload = [
            'textQuery' => $query,
            'languageCode' => 'de',
            'maxResultCount' => 20,
        ];

        if ($lat !== null && $lon !== null) {
            $payload['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => $lat,
                        'longitude' => $lon,
                    ],
                    'radius' => min(max(50.0, $radiusMeters), 50000.0),
                ],
            ];
        }

        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.location,places.regularOpeningHours,places.websiteUri,places.formattedAddress,places.types',
        ])->post($endpoint, $payload);

        $this->costService->logApiCall(
            GoogleCostService::SERVICE_PLACES_TEXT_SEARCH,
            $endpoint,
            GoogleCostService::COST_PLACES_TEXT_SEARCH_USD,
            $response->status(),
            ['query' => $query, 'lat' => $lat, 'lon' => $lon]
        );

        if ($response->failed()) {
            Log::warning('Google Text Search request failed', [
                'query' => $query,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $json = $response->json();

        return $json['places'] ?? [];
    }

    /**
     * Discover places near a coordinate using Google Places Nearby Search.
     *
     * Saves each result to the places table and creates a toilet record via
     * the website crawl logic. Only active toilets are returned.
     *
     * @return Collection<int, Toilet>
     */
    public function discoverToiletsNearby(float $lat, float $lon, float $distanceKm, ?int $limit = null): Collection
    {
        $results = $this->nearbySearchRaw($lat, $lon, $distanceKm);

        if ($limit !== null) {
            $results = array_slice($results, 0, max(1, $limit));
        }

        $created = new Collection;
        $placeToiletService = app(PlaceToiletService::class);

        foreach ($results as $result) {
            $placeId = $result['id'] ?? $result['place_id'] ?? null;

            if (! $placeId) {
                continue;
            }

            Place::updateOrCreate(
                ['place_id' => $placeId],
                ['data' => $result]
            );

            $toilet = $placeToiletService->createToiletFromPlace($result);

            if ($toilet && $toilet->status === 'active') {
                $created->push($toilet);
            }
        }

        return $created;
    }

    private function resolveSearchEngineId(string $tld): string
    {
        return match ($tld) {
            'de' => 'df9d9fec1908a93cd',
            'com' => 'eae500bbd2a5ac34d',
            'eu' => 'ef950d876c8728a7e',
            'net' => '773f01d092fb8effc',
            'org' => 'd2a4949d5717ae294',
            'info' => '51f2145633ba244cd',
            default => throw new RuntimeException('No search engine configured for .'.$tld),
        };
    }
}
