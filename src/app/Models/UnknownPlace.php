<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnknownPlace extends Model
{
    use HasFactory;

    protected $table = 'unknown_places';

    public $timestamps = false;

    protected $primaryKey = 'place_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'place_id',
        'nearby_json',
        'lat',
        'lon',
        'name',
        'country',
        'website',
        'formatted_address',
        'opening_hours',
        'details_fetched',
        'website_fetched',
    ];

    protected $casts = [
        'nearby_json' => 'array',
        'lat' => 'float',
        'lon' => 'float',
        'details_fetched' => 'boolean',
        'website_fetched' => 'boolean',
    ];
}
