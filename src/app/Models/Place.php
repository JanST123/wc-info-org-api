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
}
