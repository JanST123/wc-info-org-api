<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoogleNearbySearchCache extends Model
{
    use HasFactory;

    protected $table = 'google_nearby_search_cache';

    protected $fillable = [
        'lat',
        'lon',
        'radius_meters',
        'query_params',
        'response_places',
        'result_count',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lon' => 'float',
        'radius_meters' => 'float',
        'query_params' => 'array',
        'response_places' => 'array',
        'result_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
