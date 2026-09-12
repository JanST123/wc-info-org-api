<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Place;
use App\Models\Toilet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiPlaceMatchingService
{
    private string $geminiApiKey;
    private string $geminiModel;

    public function __construct(
        private ?GooglePlacesService $placesService = null,
        private ?GoogleCostService $costService = null,
        ?string $geminiApiKey = null,
        ?string $geminiModel = null,
    ) {
        $this->placesService = $this->placesService ?? app(GooglePlacesService::class);
        $this->costService = $this->costService ?? app(GoogleCostService::class);
        $this->geminiApiKey = $geminiApiKey ?? (string) config('wcinfo.gemini.api_key');
        $this->geminiModel = $geminiModel ?? (string) config('wcinfo.gemini.model', 'gemini-2.5-flash');
    }

    /**
     * Find and suggest a matching Google Place for a toilet using rule heuristics and Gemini AI.
     *
     * @return array<string, mixed>
     */
    public function suggestPlace(Toilet $toilet): array
    {
        if (empty($this->geminiApiKey)) {
            return [
                'success' => false,
                'error' => 'Gemini API key is not configured. Please add GEMINI_API_KEY to your .env file.',
            ];
        }

        if (! $this->costService->hasBudget()) {
            return [
                'success' => false,
                'error' => 'Monthly Google Cloud API budget exceeded.',
            ];
        }

        $toilet->loadMissing(['properties', 'place']);
        $toiletComment = $toilet->propertyValue('comment');
        $toiletAddress = $toilet->propertyValue('address');

        // 1. First Attempt: Fetch Google Places nearby (~40m = 0.04km)
        $nearbyCandidates = [];
        $hasValidCoords = $this->hasValidCoordinates($toilet->lat !== null ? (float) $toilet->lat : null, $toilet->lon !== null ? (float) $toilet->lon : null);

        if ($hasValidCoords) {
            $rawNearby = $this->placesService->nearbySearchRaw((float) $toilet->lat, (float) $toilet->lon, 0.04);
            $nearbyCandidates = $this->normalizeCandidates($rawNearby, (float) $toilet->lat, (float) $toilet->lon);
        }

        // 1a. Check for public_bathroom in nearby candidates
        $publicBathroom = $this->findPublicBathroomCandidate($nearbyCandidates);
        if ($publicBathroom !== null) {
            return $this->buildMatchResult(
                $toilet,
                $publicBathroom,
                'high',
                'Found dedicated public bathroom place within ' . ($publicBathroom['distance_m'] ?? 0) . 'm.',
                'nearby_public_bathroom'
            );
        }

        // 1b. Evaluate nearby candidates with Gemini if any exist
        if (! empty($nearbyCandidates)) {
            $aiMatch = $this->queryGeminiForMatch($toilet, $toiletAddress, $toiletComment, $nearbyCandidates);
            if ($aiMatch['match_found'] && ! empty($aiMatch['matched_place_id'])) {
                $matchedCandidate = $this->findCandidateById($nearbyCandidates, $aiMatch['matched_place_id']);
                if ($matchedCandidate !== null) {
                    return $this->buildMatchResult(
                        $toilet,
                        $matchedCandidate,
                        $aiMatch['confidence'] ?? 'medium',
                        $aiMatch['reasoning'] ?? 'AI matched toilet name/details with nearby Google Place.',
                        'nearby_gemini'
                    );
                }
            }
        }

        // 2. Second Attempt (Fallback): Fetch Google Places by Address if available
        if (! empty($toiletAddress)) {
            $rawAddressPlaces = $this->placesService->textSearchRaw(
                $toiletAddress,
                $hasValidCoords ? (float) $toilet->lat : null,
                $hasValidCoords ? (float) $toilet->lon : null,
                200.0
            );

            $addressCandidates = $this->normalizeCandidates(
                $rawAddressPlaces,
                $hasValidCoords ? (float) $toilet->lat : null,
                $hasValidCoords ? (float) $toilet->lon : null
            );

            // Check for public_bathroom in address candidates
            $publicBathroomAddr = $this->findPublicBathroomCandidate($addressCandidates);
            if ($publicBathroomAddr !== null) {
                return $this->buildMatchResult(
                    $toilet,
                    $publicBathroomAddr,
                    'high',
                    'Found public bathroom place matching address: ' . $toiletAddress,
                    'address_public_bathroom'
                );
            }

            // Evaluate address candidates with Gemini
            if (! empty($addressCandidates)) {
                $aiMatch = $this->queryGeminiForMatch($toilet, $toiletAddress, $toiletComment, $addressCandidates);
                if ($aiMatch['match_found'] && ! empty($aiMatch['matched_place_id'])) {
                    $matchedCandidate = $this->findCandidateById($addressCandidates, $aiMatch['matched_place_id']);
                    if ($matchedCandidate !== null) {
                        return $this->buildMatchResult(
                            $toilet,
                            $matchedCandidate,
                            $aiMatch['confidence'] ?? 'medium',
                            $aiMatch['reasoning'] ?? 'AI matched toilet name/details with place found via address search.',
                            'address_gemini'
                        );
                    }
                }
            }
        }

        return [
            'success' => true,
            'matched' => false,
            'toilet_id' => $toilet->id,
            'message' => 'No matching Google Place could be found.',
            'reasoning' => 'Searched nearby places (~40m)' . (! empty($toiletAddress) ? ' and address ("' . $toiletAddress . '")' : '') . ', but no place matched the toilet record with sufficient confidence.',
        ];
    }

    /**
     * Normalize and cache raw Places API results into candidate array.
     *
     * @param array<int, array> $rawPlaces
     * @return array<int, array>
     */
    private function normalizeCandidates(array $rawPlaces, ?float $refLat = null, ?float $refLon = null): array
    {
        $candidates = [];
        $seen = [];

        foreach ($rawPlaces as $item) {
            $pid = $item['id'] ?? $item['place_id'] ?? null;
            if (! $pid || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;

            // Cache place in DB
            Place::updateOrCreate(
                ['place_id' => $pid],
                ['data' => $item]
            );

            $name = $item['displayName']['text']
                ?? (is_string($item['displayName'] ?? null) ? $item['displayName'] : null)
                ?? (! str_starts_with((string) ($item['name'] ?? ''), 'places/') ? ($item['name'] ?? null) : null)
                ?? $pid;

            $address = $item['formattedAddress']
                ?? $item['formatted_address']
                ?? '';

            $types = $item['types'] ?? [];
            if (! is_array($types)) {
                $types = [];
            }

            $pLat = $item['location']['latitude'] ?? $item['location']['lat'] ?? $item['geometry']['location']['lat'] ?? null;
            $pLon = $item['location']['longitude'] ?? $item['location']['lng'] ?? $item['geometry']['location']['lng'] ?? null;

            $distance = null;
            if ($refLat !== null && $refLon !== null && $pLat !== null && $pLon !== null) {
                $distance = $this->calculateDistanceMeters($refLat, $refLon, (float) $pLat, (float) $pLon);
            }

            $candidates[] = [
                'place_id' => $pid,
                'name' => $name,
                'address' => $address,
                'types' => $types,
                'lat' => $pLat !== null ? (float) $pLat : null,
                'lon' => $pLon !== null ? (float) $pLon : null,
                'distance_m' => $distance,
            ];
        }

        return $candidates;
    }

    /**
     * Look for a public_bathroom type in candidate list.
     *
     * @param array<int, array> $candidates
     * @return array|null
     */
    private function findPublicBathroomCandidate(array $candidates): ?array
    {
        foreach ($candidates as $cand) {
            $types = $cand['types'] ?? [];
            if (in_array('public_bathroom', $types, true) || in_array('restroom', $types, true) || in_array('toilet', $types, true)) {
                return $cand;
            }
        }

        return null;
    }

    /**
     * Find candidate by place_id.
     *
     * @param array<int, array> $candidates
     */
    private function findCandidateById(array $candidates, string $placeId): ?array
    {
        foreach ($candidates as $cand) {
            if ($cand['place_id'] === $placeId) {
                return $cand;
            }
        }

        return null;
    }

    /**
     * Ask Gemini AI to pick the matching place from candidates.
     *
     * @param array<int, array> $candidates
     * @return array{match_found: bool, matched_place_id: ?string, confidence: string, reasoning: string}
     */
    private function queryGeminiForMatch(Toilet $toilet, ?string $address, ?string $comment, array $candidates): array
    {
        $candidateDescriptions = [];
        foreach ($candidates as $idx => $c) {
            $typesStr = implode(', ', $c['types'] ?? []);
            $distStr = $c['distance_m'] !== null ? " ({$c['distance_m']}m away)" : '';
            $candidateDescriptions[] = sprintf(
                "Candidate #%d:\n- Place ID: %s\n- Name: %s\n- Address: %s\n- Types: %s%s",
                $idx + 1,
                $c['place_id'],
                $c['name'],
                $c['address'] ?: 'N/A',
                $typesStr ?: 'N/A',
                $distStr
            );
        }

        $candidatesText = implode("\n\n", $candidateDescriptions);

        $prompt = <<<PROMPT
You are an expert location data matching assistant for a public toilet database (wc-info.org).
A public toilet has been flagged because it has no Google Place or possibly the wrong Google Place assigned.

Analyze the toilet record and determine which Google Place candidate (if any) it belongs to or corresponds to.

TOILET RECORD:
- ID: #{$toilet->id}
- Name: {$toilet->name}
- Owner / Facility: {$toilet->owner}
- Address property: {$address}
- Comment property: {$comment}
- Coordinates: Lat {$toilet->lat}, Lon {$toilet->lon}
- Current Assigned Place ID: {$toilet->place_id}

GOOGLE PLACE CANDIDATES:
{$candidatesText}

MATCHING RULES:
1. If a candidate is of type "public_bathroom", "restroom", or "toilet", and is near the toilet, choose it with "high" confidence.
2. If the toilet name, owner, comment, or address refers to a specific business, train station, subway station, shopping mall, museum, park, town square, library, restaurant, or public building that matches one of the candidates, choose that candidate.
3. If none of the candidates match the toilet or if the match is too ambiguous/uncertain, set "match_found" to false.
4. Do NOT guess or hallucinate.

Respond ONLY with a JSON object in this exact schema:
{
  "match_found": true or false,
  "matched_place_id": "string with place_id or null",
  "confidence": "high" or "medium" or "low",
  "reasoning": "brief explanation in English or German explaining why this candidate was chosen or why no match was found"
}
PROMPT;

        try {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiModel}:generateContent?key={$this->geminiApiKey}";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($endpoint, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'responseMimeType' => 'application/json',
                ],
            ]);

            if ($response->failed()) {
                Log::warning('Gemini API call failed', [
                    'toilet_id' => $toilet->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'match_found' => false,
                    'matched_place_id' => null,
                    'confidence' => 'low',
                    'reasoning' => 'Gemini API call failed with status ' . $response->status(),
                ];
            }

            $json = $response->json();
            $responseText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = json_decode($responseText, true);

            if (! is_array($parsed)) {
                Log::warning('Failed to parse Gemini JSON response', [
                    'response' => $responseText,
                ]);

                return [
                    'match_found' => false,
                    'matched_place_id' => null,
                    'confidence' => 'low',
                    'reasoning' => 'Could not parse Gemini JSON response',
                ];
            }

            return [
                'match_found' => (bool) ($parsed['match_found'] ?? false),
                'matched_place_id' => ! empty($parsed['matched_place_id']) ? (string) $parsed['matched_place_id'] : null,
                'confidence' => (string) ($parsed['confidence'] ?? 'medium'),
                'reasoning' => (string) ($parsed['reasoning'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::error('Gemini place matching exception', [
                'toilet_id' => $toilet->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'match_found' => false,
                'matched_place_id' => null,
                'confidence' => 'low',
                'reasoning' => 'Exception during Gemini evaluation: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build the structured successful match result.
     *
     * @param array<string, mixed> $matchedCandidate
     * @return array<string, mixed>
     */
    private function buildMatchResult(
        Toilet $toilet,
        array $matchedCandidate,
        string $confidence,
        string $reasoning,
        string $source
    ): array {
        $placeTypes = $matchedCandidate['types'] ?? [];
        $isPublicAccessible = PlaceToiletService::isPublicAccessibleType($placeTypes);

        $mapsUrl = ($toilet->lat !== null && $toilet->lon !== null)
            ? 'https://www.google.com/maps/search/?api=1&query=' . $toilet->lat . ',' . $toilet->lon . '&query_place_id=' . urlencode($matchedCandidate['place_id'])
            : 'https://www.google.com/maps/place/?q=place_id:' . urlencode($matchedCandidate['place_id']);

        return [
            'success' => true,
            'matched' => true,
            'toilet_id' => $toilet->id,
            'toilet_name' => $toilet->name ?: 'Unnamed Toilet',
            'toilet_lat' => $toilet->lat !== null ? (float) $toilet->lat : null,
            'toilet_lon' => $toilet->lon !== null ? (float) $toilet->lon : null,
            'place' => [
                'place_id' => $matchedCandidate['place_id'],
                'name' => $matchedCandidate['name'],
                'address' => $matchedCandidate['address'],
                'types' => $placeTypes,
                'lat' => $matchedCandidate['lat'],
                'lon' => $matchedCandidate['lon'],
                'distance_m' => $matchedCandidate['distance_m'],
                'maps_url' => $mapsUrl,
                'is_public_accessible' => $isPublicAccessible,
            ],
            'confidence' => $confidence,
            'reasoning' => $reasoning,
            'source' => $source,
        ];
    }

    /**
     * Calculate Haversine distance in meters between two coordinates.
     */
    private function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round($earthRadius * $c);
    }

    /**
     * Check if latitude and longitude are valid and non-zero.
     */
    private function hasValidCoordinates(?float $lat, ?float $lon): bool
    {
        if ($lat === null || $lon === null) {
            return false;
        }

        // Filter out near-zero or corrupted coordinates (e.g. 5.2e-7)
        if (abs($lat) < 1.0 && abs($lon) < 1.0) {
            return false;
        }

        return $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0;
    }
}
