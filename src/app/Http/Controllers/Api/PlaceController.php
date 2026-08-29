<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlaceCacheRequest;
use App\Http\Resources\PlaceResource;
use App\Models\Place;
use App\Models\Toilet;
use App\Models\Type;
use App\Models\TypeXPlace;
use App\Services\GooglePlacesService;
use App\Services\PlaceToiletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PlaceController extends Controller
{
    public function __construct(
        private GooglePlacesService $placesService,
        private PlaceToiletService $placeToiletService,
    ) {
    }

    /**
     * Store authoritative Google Places data for a place.
     *
     * If no toilet exists for the given `place_id`, the website is crawled and
     * a new toilet record is created when a toilet is detected.
     */
    public function store(string $placeId, StorePlaceCacheRequest $request): JsonResponse
    {
        $input = $request->validated();

        Place::updateOrCreate(
            ['place_id' => $placeId],
            ['data' => $input]
        );

        $fetchedPlace = false;
        $newToiletId = null;
        $crawlException = null;

        try {
            $toilet = $this->placeToiletService->createToiletFromPlace($input);

            if ($toilet && $toilet->source === 'auto_crawl') {
                $fetchedPlace = true;
            }

            if ($toilet && $toilet->status === 'active') {
                $newToiletId = $toilet->id;
            }
        } catch (\Throwable $e) {
            $crawlException = $e->getMessage();
            Log::warning('Place crawl failed', ['place_id' => $placeId, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'status' => 'okay',
            'fetchedPlace' => $fetchedPlace,
            'newToiletId' => $newToiletId,
            'crawlException' => $crawlException,
        ]);
    }

    /**
     * Retrieve authoritative place data.
     */
    public function show(string $placeId): JsonResponse
    {
        $place = Place::find($placeId);

        if (! $place) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json(new PlaceResource($place));
    }
}
