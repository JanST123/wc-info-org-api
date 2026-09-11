<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToiletRevision extends Model
{
    use HasFactory;

    protected $table = 'toilet_revisions';

    public $timestamps = false;

    protected $fillable = [
        'toilet_id',
        'version',
        'source',
        'toilet_data',
        'properties_data',
        'diff',
        'summary',
        'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'toilet_data' => 'array',
        'properties_data' => 'array',
        'diff' => 'array',
        'created_at' => 'datetime',
    ];

    public function toilet(): BelongsTo
    {
        return $this->belongsTo(Toilet::class, 'toilet_id');
    }
}
