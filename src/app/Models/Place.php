<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    use HasFactory;

    protected $table = 'places';

    public $timestamps = false;

    protected $primaryKey = 'place_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'place_id',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function getName(): ?string
    {
        $data = $this->data;
        if (! is_array($data)) {
            return null;
        }

        return $data['displayName']['text']
            ?? (is_string($data['displayName'] ?? null) ? $data['displayName'] : null)
            ?? (! str_starts_with((string) ($data['name'] ?? ''), 'places/') ? ($data['name'] ?? null) : null)
            ?? ($data['formattedAddress'] ?? $data['formatted_address'] ?? null);
    }

    public function getLat(): ?float
    {
        $data = $this->data;
        if (! is_array($data)) {
            return null;
        }

        $lat = $data['location']['latitude'] ?? $data['location']['lat'] ?? $data['geometry']['location']['lat'] ?? null;

        return $lat !== null ? (float) $lat : null;
    }

    public function getLon(): ?float
    {
        $data = $this->data;
        if (! is_array($data)) {
            return null;
        }

        $lon = $data['location']['longitude'] ?? $data['location']['lng'] ?? $data['geometry']['location']['lng'] ?? null;

        return $lon !== null ? (float) $lon : null;
    }
}
