<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TypeXPlace extends Model
{
    use HasFactory;

    protected $table = 'type_x_place';

    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = [
        'type_id',
        'place_id',
    ];
}
