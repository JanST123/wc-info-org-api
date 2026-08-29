<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToiletPhoto extends Model
{
    use HasFactory;

    protected $table = 'toilet_photos';

    public $timestamps = false;

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'fk_toiletId',
        'filename',
        'filename_thumb',
        'exif',
        'deleted_ts',
        'email_sent',
    ];

    protected $casts = [
        'exif' => 'array',
        'deleted_ts' => 'datetime',
        'email_sent' => 'integer',
    ];

    public function toilet(): BelongsTo
    {
        return $this->belongsTo(Toilet::class, 'fk_toiletId');
    }
}
