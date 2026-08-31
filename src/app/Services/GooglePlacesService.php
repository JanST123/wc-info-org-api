<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Toilet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GooglePlacesService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('wcinfo.google.api_key');

        if (empty($this->apiKey)) {
            throw new RuntimeException('Google API key is not configured.');
        }
    }

    public function fetchPlaceDetails(string $placeId): ?array
    {
        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'id,displayName,location,regularOpeningHours,websiteUri,formattedAddress,types',
        ])->get("https://places.googleapis.com/v1/places/{$placeId}", [
            'languageCode' => 'de',
        ]);

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
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => $lat.','.$lon,
            'key' => $this->apiKey,
        ]);

        if ($response->failed()) {
            Log::warning('Google Geocoding request failed', ['lat' => $lat, 'lon' => $lon]);

            return [];
        }

        $json = $response->json();

        return $json['status'] === 'OK' ? $json['results'] : [];
    }

    public function crawlWebsite(string $url, string $term): array
    {
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

        $response = Http::get('https://customsearch.googleapis.com/customsearch/v1', [
            'cx' => $cx,
            'exactTerms' => $term,
            'siteSearch' => $url,
            'key' => $this->apiKey,
        ]);

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
     * @return array<int, array>
     */
    public function nearbySearchRaw(float $lat, float $lon, float $distanceKm): array
    {
        $radius = (float) min(round($distanceKm * 1000, 1), 50000);

        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.location,places.regularOpeningHours,places.websiteUri,places.formattedAddress,places.types',
        ])->post('https://places.googleapis.com/v1/places:searchNearby', [
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
            'maxResultCount' => 20,
        ]);

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
