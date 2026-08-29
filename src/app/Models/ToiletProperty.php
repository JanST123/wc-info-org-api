<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToiletProperty extends Model
{
    use HasFactory;

    protected $table = 'toilet_properties';

    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Allowed property types.
     *
     * @var string[]
     */
    public const TYPES = [
        'address',
        'website',
        'euro_key',
        'comment',
        'place_opening_hours',
        'is_unisex',
        'is_gender_separated',
        'has_wheelchair_access',
        'has_changing_table',
        'accessible_outside_opening_times',
        'public_accessible',
        'storage_space',
    ];

    protected $fillable = [
        'fk_toiletId',
        'type',
        'value',
        'user_overridden',
    ];

    protected $casts = [
        'user_overridden' => 'boolean',
    ];

    public function toilet(): BelongsTo
    {
        return $this->belongsTo(Toilet::class, 'fk_toiletId');
    }
}
