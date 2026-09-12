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

        // 1b. Check for high-confidence establishment name match in nearby candidates
        $nameMatch = $this->findEstablishmentNameMatch($toilet, $nearbyCandidates);
        if ($nameMatch !== null && ($nameMatch['score'] ?? 0) >= 0.7) {
            return $this->buildMatchResult(
                $toilet,
                $nameMatch['candidate'],
                'high',
                $nameMatch['reason'],
                'nearby_name_match'
            );
        }

        // 1c. Evaluate nearby candidates with Gemini if any exist
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
            } elseif ($nameMatch !== null && ($nameMatch['score'] ?? 0) >= 0.6) {
                // Fallback to name match if Gemini was inconclusive or had an API error
                return $this->buildMatchResult(
                    $toilet,
                    $nameMatch['candidate'],
                    'medium',
                    $nameMatch['reason'],
                    'nearby_name_match'
                );
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

            // Check for establishment name match in address candidates
            $addrNameMatch = $this->findEstablishmentNameMatch($toilet, $addressCandidates);
            if ($addrNameMatch !== null && ($addrNameMatch['score'] ?? 0) >= 0.7) {
                return $this->buildMatchResult(
                    $toilet,
                    $addrNameMatch['candidate'],
                    'high',
                    $addrNameMatch['reason'],
                    'address_name_match'
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
                } elseif ($addrNameMatch !== null && ($addrNameMatch['score'] ?? 0) >= 0.6) {
                    return $this->buildMatchResult(
                        $toilet,
                        $addrNameMatch['candidate'],
                        'medium',
                        $addrNameMatch['reason'],
                        'address_name_match'
                    );
                }
            }
        }

        // 3. Third Attempt (Fallback): Fetch Google Places by exact Toilet Name
        $toiletName = trim((string) ($toilet->name ?? ''));
        $nameCandidates = [];
        $genericNames = ['wc', 'toilette', 'toiletten', 'klo', 'restroom', 'bathroom', 'public toilet', 'öffentliche toilette'];

        if ($toiletName !== '' && ! in_array(mb_strtolower($toiletName), $genericNames, true)) {
            $rawNamePlaces = $this->placesService->textSearchRaw(
                $toiletName,
                $hasValidCoords ? (float) $toilet->lat : null,
                $hasValidCoords ? (float) $toilet->lon : null,
                1000.0
            );

            $nameCandidates = $this->normalizeCandidates(
                $rawNamePlaces,
                $hasValidCoords ? (float) $toilet->lat : null,
                $hasValidCoords ? (float) $toilet->lon : null
            );

            // Check for public_bathroom in name candidates
            $publicBathroomName = $this->findPublicBathroomCandidate($nameCandidates);
            if ($publicBathroomName !== null) {
                return $this->buildMatchResult(
                    $toilet,
                    $publicBathroomName,
                    'high',
                    'Found public bathroom place matching toilet name: ' . $toiletName,
                    'name_public_bathroom'
                );
            }

            // Check for establishment name match in name candidates
            $nameSearchMatch = $this->findEstablishmentNameMatch($toilet, $nameCandidates);
            if ($nameSearchMatch !== null && ($nameSearchMatch['score'] ?? 0) >= 0.7) {
                return $this->buildMatchResult(
                    $toilet,
                    $nameSearchMatch['candidate'],
                    'high',
                    $nameSearchMatch['reason'],
                    'name_search_match'
                );
            }

            // Evaluate name search candidates with Gemini
            if (! empty($nameCandidates)) {
                $aiMatch = $this->queryGeminiForMatch($toilet, $toiletAddress, $toiletComment, $nameCandidates);
                if ($aiMatch['match_found'] && ! empty($aiMatch['matched_place_id'])) {
                    $matchedCandidate = $this->findCandidateById($nameCandidates, $aiMatch['matched_place_id']);
                    if ($matchedCandidate !== null) {
                        return $this->buildMatchResult(
                            $toilet,
                            $matchedCandidate,
                            $aiMatch['confidence'] ?? 'medium',
                            $aiMatch['reasoning'] ?? 'AI matched toilet with place found via name search.',
                            'name_search_gemini'
                        );
                    }
                } elseif ($nameSearchMatch !== null && ($nameSearchMatch['score'] ?? 0) >= 0.6) {
                    return $this->buildMatchResult(
                        $toilet,
                        $nameSearchMatch['candidate'],
                        'medium',
                        $nameSearchMatch['reason'],
                        'name_search_match'
                    );
                }
            }
        }

        // 4. Fourth Attempt (Fallback): Search websites of candidate places for toilet information
        $allCandidatesWithWebsites = array_filter(
            array_merge($nearbyCandidates, $addressCandidates ?? [], $nameCandidates),
            fn ($c) => ! empty($c['website']) && preg_match('/^https?:\/\/[a-z0-9öäüß_.-]+\.(de|com|net|eu|org|info)/i', (string) $c['website'])
        );

        $uniqueWebsiteCandidates = [];
        foreach ($allCandidatesWithWebsites as $c) {
            if (! isset($uniqueWebsiteCandidates[$c['place_id']])) {
                $uniqueWebsiteCandidates[$c['place_id']] = $c;
            }
        }

        if (! empty($uniqueWebsiteCandidates) && $this->costService->hasBudget()) {
            $confirmedCandidates = [];
            foreach (array_slice(array_values($uniqueWebsiteCandidates), 0, 5) as $cand) {
                if (! $this->costService->hasBudget()) {
                    break;
                }

                try {
                    $crawlResult = $this->placesService->crawlWebsite((string) $cand['website'], 'toilet');
                    if (($crawlResult['toiletType'] ?? 'none') !== 'none' || ($crawlResult['resultCount'] ?? 0) > 0) {
                        $cand['website_crawl'] = $crawlResult;
                        $confirmedCandidates[] = $cand;
                    }
                } catch (\Throwable $e) {
                    Log::info('Website crawl in AI matcher skipped/failed', [
                        'place_id' => $cand['place_id'],
                        'website' => $cand['website'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! empty($confirmedCandidates)) {
                $aiMatch = $this->queryGeminiForMatch($toilet, $toiletAddress, $toiletComment, $confirmedCandidates);
                if ($aiMatch['match_found'] && ! empty($aiMatch['matched_place_id'])) {
                    $matchedCandidate = $this->findCandidateById($confirmedCandidates, $aiMatch['matched_place_id']);
                    if ($matchedCandidate !== null) {
                        return $this->buildMatchResult(
                            $toilet,
                            $matchedCandidate,
                            $aiMatch['confidence'] ?? 'medium',
                            $aiMatch['reasoning'] ?? 'Place website (' . $matchedCandidate['website'] . ') confirmed toilet facilities on site.',
                            'website_crawl'
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
            'reasoning' => 'Searched nearby places (~40m)'
                . (! empty($toiletAddress) ? ' and address ("' . $toiletAddress . '")' : '')
                . ($toiletName !== '' ? ' and name ("' . $toiletName . '")' : '')
                . ' and crawled place websites, but no place matched the toilet record with sufficient confidence.',
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

            $website = $item['websiteUri']
                ?? $item['website']
                ?? null;

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
                'website' => $website,
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
     * Find a candidate place whose name strongly matches the toilet's name or owner.
     *
     * @param array<int, array> $candidates
     * @return array{candidate: array, score: float, reason: string}|null
     */
    private function findEstablishmentNameMatch(Toilet $toilet, array $candidates): ?array
    {
        $toiletName = trim((string) ($toilet->name ?? ''));
        $toiletOwner = trim((string) ($toilet->owner ?? ''));

        if ($toiletName === '' && $toiletOwner === '') {
            return null;
        }

        $stopwords = [
            'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'eines',
            'am', 'im', 'vom', 'beim', 'zum', 'zur', 'und', 'and', 'the', 'von',
            'für', 'fuer', 'mit', 'auf', 'vor', 'nach', 'bei', 'in', 'an', 'wc',
            'toilette', 'toiletten', 'klo', 'restroom', 'bathroom',
        ];

        $bestCandidate = null;
        $bestScore = 0.0;
        $bestReason = '';

        foreach ($candidates as $cand) {
            $candName = trim((string) ($cand['name'] ?? ''));
            if ($candName === '') {
                continue;
            }

            foreach ([$toiletName, $toiletOwner] as $sourceName) {
                if ($sourceName === '') {
                    continue;
                }

                $cleanSource = mb_strtolower($sourceName);
                $cleanCand = mb_strtolower($candName);

                if ($cleanSource === $cleanCand) {
                    return [
                        'candidate' => $cand,
                        'score' => 1.0,
                        'reason' => "Exact name match between toilet ('{$sourceName}') and Google Place ('{$candName}').",
                    ];
                }

                $srcWords = array_values(array_filter(
                    preg_split('/[\s,.\-_&+\/()]+/u', $cleanSource) ?: [],
                    fn ($w) => mb_strlen($w) > 1 && ! in_array($w, $stopwords, true)
                ));

                $candWords = array_values(array_filter(
                    preg_split('/[\s,.\-_&+\/()]+/u', $cleanCand) ?: [],
                    fn ($w) => mb_strlen($w) > 1 && ! in_array($w, $stopwords, true)
                ));

                if (empty($srcWords) || empty($candWords)) {
                    continue;
                }

                $common = array_intersect($candWords, $srcWords);
                $commonCount = count($common);
                if ($commonCount === 0) {
                    continue;
                }

                $candCoverage = $commonCount / count($candWords);
                $srcCoverage = $commonCount / count($srcWords);
                $isSubstring = str_contains($cleanSource, $cleanCand) || str_contains($cleanCand, $cleanSource);

                $score = ($candCoverage * 0.6) + ($srcCoverage * 0.3) + ($isSubstring ? 0.3 : 0.0);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCandidate = $cand;
                    if ($isSubstring) {
                        $bestReason = "Google Place name '{$candName}' is directly contained in toilet name '{$sourceName}'.";
                    } else {
                        $bestReason = sprintf(
                            "Strong name similarity (%d matching words: %s) between toilet '%s' and place '%s'.",
                            $commonCount,
                            implode(', ', $common),
                            $sourceName,
                            $candName
                        );
                    }
                }
            }
        }

        if ($bestCandidate !== null && $bestScore >= 0.6) {
            return [
                'candidate' => $bestCandidate,
                'score' => round($bestScore, 3),
                'reason' => $bestReason,
            ];
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
            $websiteStr = '';
            if (! empty($c['website'])) {
                $websiteStr = "\n- Website: {$c['website']}";
                if (! empty($c['website_crawl'])) {
                    $crawlType = $c['website_crawl']['toiletType'] ?? 'none';
                    $crawlMatches = $c['website_crawl']['resultCount'] ?? 0;
                    $websiteStr .= " (Website search confirmed toilet facilities: type '{$crawlType}', {$crawlMatches} mentions found)";
                }
            }

            $nameHint = $this->calculateNameMatchHint((string) ($toilet->name ?? ''), (string) ($c['name'] ?? ''));
            if ($nameHint === null && ! empty($toilet->owner)) {
                $nameHint = $this->calculateNameMatchHint((string) $toilet->owner, (string) ($c['name'] ?? ''));
            }
            $nameHintStr = $nameHint !== null ? "\n- Name Match Indicator: {$nameHint}" : '';

            $candidateDescriptions[] = sprintf(
                "Candidate #%d:\n- Place ID: %s\n- Name: %s\n- Address: %s\n- Types: %s%s%s%s",
                $idx + 1,
                $c['place_id'],
                $c['name'],
                $c['address'] ?: 'N/A',
                $typesStr ?: 'N/A',
                $nameHintStr,
                $websiteStr,
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

MATCHING RULES & PRIORITIES:
1. PRIORITY 1 — Dedicated Public Bathroom: If a candidate is of type "public_bathroom", "restroom", or "toilet", and is in close proximity to the toilet, choose it with "high" confidence.
2. PRIORITY 2 — Name / Business / Establishment Match: Many public toilets in this database are customer toilets or facilities situated inside, belonging to, or operated by specific establishments (food stands/kiosks/stands like "Standl", bakeries, cafes, restaurants, bars, shops, supermarkets, gas stations, hotels, malls, transit stations, museums, parks, or public buildings).
   - Compound / Location-prefixed names: Toilet names very often combine a location/market/station name with the business/establishment name (e.g., toilet "Elisabethmarkt Eis & Brot Standl" directly matches candidate place "Eis & Brot Standl"; toilet "München Hbf Yormas" matches candidate "Yormas"; toilet "Englischer Garten Seehaus" matches "Seehaus im Englischen Garten").
   - If a candidate's name is contained within the toilet name (or vice versa), or shares the core brand/venue name with the toilet name/owner/comment, this is a STRONG match indicator. Choose this candidate with "high" or "medium" confidence.
3. PRIORITY 3 — Website Toilet Verification: If candidate websites were searched and confirm on-site toilet facilities, consider this strong evidence that the toilet belongs to that place.
4. If none of the candidates match the toilet (e.g. completely unrelated establishments with no name overlap or facility connection), set "match_found" to false.
5. Do NOT guess or hallucinate.

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

    /**
     * Calculate name match hints between toilet name and candidate place name.
     */
    private function calculateNameMatchHint(string $toiletName, string $candidateName): ?string
    {
        $toiletName = trim($toiletName);
        $candidateName = trim($candidateName);

        if ($toiletName === '' || $candidateName === '') {
            return null;
        }

        $cleanToilet = mb_strtolower($toiletName);
        $cleanCandidate = mb_strtolower($candidateName);

        if ($cleanToilet === $cleanCandidate) {
            return "Exact name match with toilet ('{$toiletName}')";
        }

        if (str_contains($cleanToilet, $cleanCandidate)) {
            return "Candidate name '{$candidateName}' is a direct substring of toilet name '{$toiletName}' (Strong Match Indicator)";
        }

        if (str_contains($cleanCandidate, $cleanToilet)) {
            return "Toilet name '{$toiletName}' is a direct substring of candidate name '{$candidateName}' (Strong Match Indicator)";
        }

        // Word token overlap
        $toiletWords = array_values(array_filter(preg_split('/[\s,.\-_&+\/]+/u', $cleanToilet) ?: [], fn ($w) => mb_strlen($w) > 2));
        $candWords = array_values(array_filter(preg_split('/[\s,.\-_&+\/]+/u', $cleanCandidate) ?: [], fn ($w) => mb_strlen($w) > 2));

        if (! empty($toiletWords) && ! empty($candWords)) {
            $commonWords = array_intersect($candWords, $toiletWords);
            $overlapRatio = count($commonWords) / count($candWords);
            if ($overlapRatio >= 0.6 || count($commonWords) >= 2) {
                return sprintf(
                    "High word overlap (%d shared words: %s) between candidate '%s' and toilet '%s' (Strong Match Indicator)",
                    count($commonWords),
                    implode(', ', $commonWords),
                    $candidateName,
                    $toiletName
                );
            }
        }

        return null;
    }
}
