<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Health check endpoint.
     *
     * Returns a simple status object to verify the API is running.
     */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'okay']);
    }
}
