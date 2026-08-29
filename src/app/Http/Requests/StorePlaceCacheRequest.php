<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlaceCacheRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'place_id' => ['nullable', 'string'],
            'displayName' => ['nullable'],
            'displayName.text' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'websiteUri' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'formattedAddress' => ['nullable', 'string'],
            'formatted_address' => ['nullable', 'string'],
            'location' => ['nullable', 'array'],
            'location.latitude' => ['nullable', 'numeric'],
            'location.longitude' => ['nullable', 'numeric'],
            'location.lat' => ['nullable', 'numeric'],
            'location.lng' => ['nullable', 'numeric'],
            'geometry' => ['nullable', 'array'],
            'geometry.location' => ['nullable', 'array'],
            'geometry.location.lat' => ['nullable', 'numeric'],
            'geometry.location.lng' => ['nullable', 'numeric'],
            'regularOpeningHours' => ['nullable', 'array'],
            'opening_hours' => ['nullable', 'array'],
            'openingHours' => ['nullable', 'array'],
            'types' => ['nullable', 'array'],
        ];
    }
}
