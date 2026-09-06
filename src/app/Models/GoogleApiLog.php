<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoogleApiLog extends Model
{
    use HasFactory;

    protected $table = 'google_api_logs';

    public $timestamps = false;

    protected $fillable = [
        'service',
        'endpoint',
        'cost_usd',
        'status_code',
        'context',
        'created_at',
    ];

    protected $casts = [
        'cost_usd' => 'float',
        'status_code' => 'integer',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('created_at', $year)->whereMonth('created_at', $month);
    }
}
