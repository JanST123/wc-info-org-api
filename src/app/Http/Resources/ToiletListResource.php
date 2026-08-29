<?php

namespace App\Http\Resources;

use App\Services\OpeningHoursService;
use App\Services\S3PhotoStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToiletListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s3 = app(S3PhotoStorageService::class);
        $openingHours = app(OpeningHoursService::class);

        $photos = [];
        foreach ($this->photos as $photo) {
            $url = $s3->url($this->id, $photo->filename);
            $thumbUrl = $s3->url($this->id, $photo->filename_thumb);

            if ($url && $thumbUrl) {
                $photos[] = [
                    'url' => $url,
                    'url_thumb' => $thumbUrl,
                ];
            }
        }

        $periods = $this->propertyValue('place_opening_hours');

        if (isset($this->resource->is_open)) {
            $isOpen = $this->resource->is_open;
            $openTimestamp = $this->resource->open_timestamp;
            $closeTimestamp = $this->resource->close_timestamp;
        } else {
            $state = $openingHours->getOpenState($periods);
            $isOpen = $state['is_open'];
            $openTimestamp = $state['open_timestamp'];
            $closeTimestamp = $state['close_timestamp'];
        }

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
            'address' => $this->propertyValue('address'),
            'comment' => $this->propertyValue('comment'),
            'website' => $this->propertyValue('website'),
            'euro_key' => $this->propertyValue('euro_key'),
            'storage_space' => $this->propertyValue('storage_space'),
            'place_opening_hours' => $periods ? json_decode($periods, true) : null,
            'is_unisex' => $this->isFlagSet('is_unisex'),
            'is_gender_separated' => $this->isFlagSet('is_gender_separated'),
            'has_wheelchair_access' => $this->isFlagSet('has_wheelchair_access'),
            'has_changing_table' => $this->isFlagSet('has_changing_table'),
            'accessible_outside_opening_times' => $this->isFlagSet('accessible_outside_opening_times'),
            'public_accessible' => $this->isFlagSet('public_accessible'),
            'photos' => $photos,
            'is_open' => $isOpen,
            'open_timestamp' => $openTimestamp,
            'close_timestamp' => $closeTimestamp,
            'updated' => $this->updated,
            'distance' => $this->when(isset($this->distance), $this->distance),
        ];
    }
}
