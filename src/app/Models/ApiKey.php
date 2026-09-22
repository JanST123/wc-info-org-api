<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory;

    protected $table = 'api_keys';

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
        'rate_limit_per_minute',
        'rate_limit_penalty_period',
        'rate_limit_block_duration',
        'global_rate_limit_per_minute',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
            'rate_limit_penalty_period' => 'integer',
            'rate_limit_block_duration' => 'integer',
            'global_rate_limit_per_minute' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Generate a new unique API key token.
     */
    public static function generateKey(string $prefix = 'wc_'): string
    {
        return $prefix . Str::random(32);
    }
}
