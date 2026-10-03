<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Type extends Model
{
    use HasFactory;

    protected $table = 'types';

    public $timestamps = false;

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'type',
        'priorize',
    ];

    protected function casts(): array
    {
        return [
            'priorize' => 'integer',
        ];
    }

    public function places(): BelongsToMany
    {
        return $this->belongsToMany(
            Place::class,
            'type_x_place',
            'type_id',
            'place_id'
        );
    }
}
