<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string|null $status Status of the toilet ('active', 'hidden', 'deleted')
 * @property string|null $name Name or label of the toilet
 * @property string|null $owner Owner or organization running the toilet
 * @property float|null $lat Latitude coordinate (-90 to 90)
 * @property float|null $lon Longitude coordinate (-180 to 180)
 * @property string|null $source Source origin identifier of the toilet record
 * @property string|null $place_id Google Places place_id
 * @property bool|null $is_unisex Whether the toilet is unisex (mutually exclusive with is_gender_separated)
 * @property bool|null $is_gender_separated Whether the toilet is gender-separated (mutually exclusive with is_unisex)
 * @property bool|null $has_wheelchair_access Whether the toilet is wheelchair accessible
 * @property bool|null $has_changing_table Whether the toilet has a baby changing table
 * @property bool|null $accessible_outside_opening_times Whether the toilet is accessible outside opening times
 * @property bool|null $public_accessible Whether the toilet is publicly accessible
 * @property array<int, array{open: array{day: int, hour: int, minute: int}, close?: array{day: int, hour: int, minute: int}}>|null $place_opening_hours Opening hours periods array in Places API (New) format
 * @property string|null $address Address string
 * @property string|null $comment Comment or additional details
 * @property string|null $website Website URL
 * @property 'yes'|'no'|'unknown'|null $euro_key Euro key access status ("yes", "no", "unknown")
 * @property 'none'|'little'|'much'|null $storage_space Available storage space ("none", "little", "much")
 */
class StoreToiletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,hidden,deleted'],
            'name' => ['nullable', 'string', 'max:200'],
            'owner' => ['nullable', 'string', 'max:200'],
            'source' => ['nullable', 'string', 'max:45'],
            'lat' => ['required_without:place_id', 'nullable', 'numeric', 'between:-90,90'],
            'lon' => ['required_without:place_id', 'nullable', 'numeric', 'between:-180,180'],
            'place_id' => ['required_without_all:lat,lon', 'nullable', 'string'],
            'is_unisex' => ['nullable', 'boolean'],
            'is_gender_separated' => ['nullable', 'boolean'],
            'has_wheelchair_access' => ['nullable', 'boolean'],
            'has_changing_table' => ['nullable', 'boolean'],
            'accessible_outside_opening_times' => ['nullable', 'boolean'],
            'public_accessible' => ['nullable', 'boolean'],
            'place_opening_hours' => ['nullable', 'array'],
            'address' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'euro_key' => ['nullable', 'string', 'in:yes,no,unknown'],
            'storage_space' => ['nullable', 'string', 'in:none,little,much'],
        ];
    }

    public function messages(): array
    {
        return [
            'lat.required_without' => 'Either `lat` and `lon` must be given or `place_id` is required',
            'lon.required_without' => 'Either `lat` and `lon` must be given or `place_id` is required',
            'place_id.required_without_all' => 'Either `lat` and `lon` must be given or `place_id` is required',
            'euro_key.in' => 'Field `euro_key` allows only values: "yes", "no", "unknown"',
            'storage_space.in' => 'Field `storage_space` allows only values: "none", "little", "much"',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('is_unisex') && $this->boolean('is_gender_separated')) {
                $validator->errors()->add('is_unisex', '"is_unisex" and "is_gender_separated" cannot both be true.');
            }
        });
    }
}
