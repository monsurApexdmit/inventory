<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'address',
        'contact_person',
        'type',
        'district',
        'phone',
        'manager',
        'is_default',
        'sells_in_pos',
        'ships_online',
        'is_pickup_point',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sells_in_pos' => 'boolean',
        'ships_online' => 'boolean',
        'is_pickup_point' => 'boolean',
    ];

    /**
     * Locations customers can collect storefront orders from: the dedicated is_pickup_point flag,
     * falling back to ships_online only on databases that predate that column.
     */
    public function scopePickupPoints($query)
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('locations', 'is_pickup_point')) {
            return $query->where('is_pickup_point', true);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('locations', 'ships_online')) {
            return $query->where('ships_online', true);
        }

        return $query;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
