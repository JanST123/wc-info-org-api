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

    private string $geminiApiKey;

    private string $geminiModel;

    private GoogleCostService $costService;

    private GoogleNearbyCacheService $cacheService;

    public function __construct(
        ?GoogleCostService $costService = null,
        ?GoogleNearbyCacheService $cacheService = null,
    ) {
        $this->apiKey = (string) config('wcinfo.google.api_key');
        $this->geminiApiKey = (string) (config('wcinfo.gemini.api_key') ?: $this->apiKey);
        $this->geminiModel = (string) config('wcinfo.gemini.model', 'gemini-3.8-flash');
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

        Log::info('Starting website crawl via Gemini Grounding & URL Context', ['url' => $url, 'term' => $term]);

        if (! preg_match('/^https?:\/\/[a-z0-9öäüß_.-]+\.[a-z]{2,}/i', $url)) {
            Log::warning('Website crawl rejected: unsupported URL pattern', ['url' => $url]);
            throw new RuntimeException('Unsupported url pattern: '.$url);
        }

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiModel}:generateContent";
        $prompt = <<<PROMPT
Analyze the website {$url} (including its subpages like Impressum/Kontakt if needed via Google Search on that domain) to check for any information regarding restroom / toilet / WC facilities on site and a general contact email address (e.g. from the Impressum or contact page).
Search terms of interest: {$term}

Determine the following:
1. "has_toilet": true if the website mentions having a toilet, WC, restroom, or customer bathroom on site, otherwise false.
2. "is_gender_separated": true if the website mentions separate women's/men's toilets (e.g. Damen/Herren, m/w, getrennt), otherwise false.
3. "is_wheelchair_accessible": true if the website mentions wheelchair accessible / barrier-free toilets (e.g. barrierefrei, Behinderten-WC, Rollstuhl), otherwise false.
4. "has_changing_table": true if the website mentions a baby changing table (e.g. Wickeltisch, Wickelraum, Mutter-Kind), otherwise false.
5. "contact_email": a valid contact email address found on the website (or "" if none found).
6. "result_count": number of relevant mentions or pages found on the website (integer >= 0).
7. "snippets": array of short text snippets from the website where toilet or contact info was found.

Respond ONLY with a valid JSON object matching this exact structure:
{
  "has_toilet": false,
  "is_gender_separated": false,
  "is_wheelchair_accessible": false,
  "has_changing_table": false,
  "contact_email": "",
  "result_count": 0,
  "snippets": []
}
PROMPT;

        $response = Http::withHeaders([
            'x-goog-api-key' => $this->geminiApiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($endpoint, [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'tools' => [
                ['url_context' => (object) []],
                ['google_search' => (object) []],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'thinkingConfig' => [
                    'thinkingLevel' => 'low',
                ],
            ],
        ]);

        $this->costService->logApiCall(
            GoogleCostService::SERVICE_CUSTOM_SEARCH,
            $endpoint,
            GoogleCostService::COST_CUSTOM_SEARCH_USD,
            $response->status(),
            ['url' => $url, 'term' => $term]
        );

        if ($response->failed()) {
            Log::warning('Gemini website crawl request failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Gemini website crawl request failed');
        }

        $json = $response->json();
        $responseText = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $responseText .= $part['text'];
            }
        }

        $cleanJson = trim($responseText);
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $cleanJson, $jsonMatches)) {
            $cleanJson = $jsonMatches[1];
        }

        $parsed = json_decode($cleanJson, true);
        if (! is_array($parsed)) {
            Log::warning('Failed to parse Gemini website crawl JSON response', [
                'url' => $url,
                'response' => $responseText,
            ]);
            $parsed = [];
        }

        $hasToilet = (bool) ($parsed['has_toilet'] ?? false);
        $isGenderSeparated = (bool) ($parsed['is_gender_separated'] ?? false);
        $isWheelchair = (bool) ($parsed['is_wheelchair_accessible'] ?? false);
        $hasChangingTable = (bool) ($parsed['has_changing_table'] ?? false);
        $contactEmail = trim((string) ($parsed['contact_email'] ?? ''));
        $snippets = is_array($parsed['snippets'] ?? null) ? $parsed['snippets'] : [];
        $resultCount = isset($parsed['result_count']) ? (int) $parsed['result_count'] : count($snippets);

        // Also run regex fallback on any returned snippets to preserve exact legacy keyword detection
        foreach ($snippets as $snippet) {
            $snippetStr = (string) $snippet;
            if (empty($contactEmail) && preg_match("/([a-z0-9!#$%&'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)/i", $snippetStr, $emailMatches)) {
                $contactEmail = $emailMatches[1];
            }
            if (preg_match('/\b(toilet|wc|klo|restroom)/i', $snippetStr)) {
                $hasToilet = true;
            }
            $lowerSnippet = strtolower($snippetStr);
            if (preg_match('/\b(dame|herr|man|men|woman|women|getrennt)/i', $lowerSnippet)
                || str_contains($lowerSnippet, 'm/w')
                || str_contains($lowerSnippet, 'm|w')) {
                $isGenderSeparated = true;
            }
            if (preg_match('/\b(barrieref|behindert|rollstuhl)/i', $lowerSnippet)) {
                $isWheelchair = true;
            }
            if (preg_match('/\b(wickel|mutter|changing)/i', $lowerSnippet)) {
                $hasChangingTable = true;
            }
        }

        if (! empty($contactEmail) && ! preg_match("/^([a-z0-9!#$%&'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)$/i", $contactEmail)) {
            $contactEmail = '';
        }

        $toiletType = 'none';
        if ($hasToilet || $isGenderSeparated || $isWheelchair || $hasChangingTable) {
            $toiletType = $isGenderSeparated ? 'mw' : 'forall';

            if ($isWheelchair) {
                if ($toiletType === 'forall') {
                    $toiletType = 'mw';
                }
                $toiletType .= 'd';
            }

            if ($hasChangingTable) {
                if ($toiletType === 'forall') {
                    $toiletType = 'mw';
                }
                $toiletType .= 'b';
            }
        }

        if ($toiletType !== 'none' && $resultCount === 0) {
            $resultCount = 1;
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
}
