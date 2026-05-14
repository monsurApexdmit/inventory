<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TailorFabric extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tailor_fabrics';

    protected $fillable = [
        'company_id', 'name', 'fabric_type', 'color', 'pattern',
        'unit', 'purchase_price', 'selling_price', 'stock_quantity',
        'vendor_id', 'image_path', 'status',
    ];

    protected $casts = [
        'purchase_price'  => 'float',
        'selling_price'   => 'float',
        'stock_quantity'  => 'float',
    ];

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Vendor::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(TailorOrderItem::class, 'fabric_id');
    }
}
