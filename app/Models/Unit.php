<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'unit_name',
        'symbol',
        'type',
        'conversion_qty',
        'conversion_unit_id',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'conversion_qty' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function conversionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'conversion_unit_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_id');
    }
}
