<?php

namespace App\Http\Resources;

use App\Services\OpeningHoursService;
use App\Services\S3PhotoStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToiletDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s3 = app(S3PhotoStorageService::class);
        $openingHours = app(OpeningHoursService::class);

        $properties = [];
        foreach ($this->properties as $property) {
            $properties[$property->type] = $property->value;
        }

        $photos = [];
        foreach ($this->photos as $photo) {
            $url = $s3->url($this->id, $photo->filename);
            $thumbUrl = $s3->url($this->id, $photo->filename_thumb);

            if ($url && $thumbUrl) {
                $photos[] = [
                    'id' => $photo->id,
                    'url' => $url,
                    'url_thumb' => $thumbUrl,
                ];
            }
        }

        $periods = $properties['place_opening_hours'] ?? null;
        $state = $openingHours->getOpenState($periods);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner' => $this->owner,
            'lat' => $this->lat,
            'lon' => $this->lon,
            'place_id' => $this->place_id,
            'status' => $this->status,
            'is_qualified' => (bool) $this->is_qualified,
            'source' => $this->source,
            'properties' => [
                'address' => $properties['address'] ?? null,
                'comment' => $properties['comment'] ?? null,
                'website' => $properties['website'] ?? null,
                'euro_key' => $properties['euro_key'] ?? null,
                'storage_space' => $properties['storage_space'] ?? null,
                'place_opening_hours' => $periods ? json_decode($periods, true) : null,
            ],
            'flags' => [
                'is_unisex' => $this->isFlagSet('is_unisex'),
                'is_gender_separated' => $this->isFlagSet('is_gender_separated'),
                'has_wheelchair_access' => $this->isFlagSet('has_wheelchair_access'),
                'has_changing_table' => $this->isFlagSet('has_changing_table'),
                'accessible_outside_opening_times' => $this->isFlagSet('accessible_outside_opening_times'),
                'public_accessible' => $this->isFlagSet('public_accessible'),
            ],
            'photos' => $photos,
            'is_open' => $state['is_open'],
            'open_timestamp' => $state['open_timestamp'],
            'close_timestamp' => $state['close_timestamp'],
            'updated' => $this->updated,
        ];
    }
}
