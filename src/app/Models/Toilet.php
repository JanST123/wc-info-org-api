<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Toilet extends Model
{
    use HasFactory;

    protected $table = 'toilets';

    public $timestamps = false;

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'name',
        'owner',
        'lat',
        'lon',
        'place_id',
        'status',
        'is_qualified',
        'contact_email',
        'source',
        'created_at',
        'last_included',
        'last_discovered',
        'last_places_fetch',
        'last_crawled',
        'user_overridden',
        'email_sent',
        'last_diff',
    ];

    protected $casts = [
        'lat' => 'float',
        'lon' => 'float',
        'is_qualified' => 'boolean',
        'email_sent' => 'integer',
        'created_at' => 'datetime',
        'updated' => 'datetime',
        'last_included' => 'datetime',
        'last_discovered' => 'datetime',
        'last_places_fetch' => 'datetime',
        'last_crawled' => 'datetime',
        'user_overridden' => 'array',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(ToiletProperty::class, 'fk_toiletId');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ToiletPhoto::class, 'fk_toiletId')->whereNull('deleted_ts');
    }

    public function place()
    {
        return $this->hasOne(Place::class, 'place_id', 'place_id');
    }

    public function scopeVisible($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeQualified($query)
    {
        return $query->where('is_qualified', 1);
    }

    public function isFlagSet(string $flag): bool
    {
        if ($this->relationLoaded('properties')) {
            return $this->properties
                ->where('type', $flag)
                ->where('value', '1')
                ->isNotEmpty();
        }

        return $this->properties()
            ->where('type', $flag)
            ->where('value', '1')
            ->exists();
    }

    public function propertyValue(string $type): ?string
    {
        if ($this->relationLoaded('properties')) {
            $property = $this->properties->firstWhere('type', $type);

            return $property?->value;
        }

        $property = $this->properties()->where('type', $type)->first();

        return $property?->value;
    }

    public function isUserOverridden(string $field): bool
    {
        $fields = $this->user_overridden ?? [];

        return is_array($fields) && in_array($field, $fields, true);
    }

    public function markUserOverridden(string ...$fields): void
    {
        $existing = $this->user_overridden ?? [];

        if (! is_array($existing)) {
            $existing = [];
        }

        $merged = array_values(array_unique(array_merge($existing, $fields)));

        if ($merged !== $existing) {
            $this->update(['user_overridden' => $merged]);
        }
    }
}
