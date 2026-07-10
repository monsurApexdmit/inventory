<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'attributes',
        'price',
        'sale_price',
        'offer_price',
        'offer_type',
        'cost_price',
        'profit_margin',
        'margin_type',
        'stock',
        'reorder_point',
        'tracking_type',
        'sku',
        'barcode',
    ];

    protected $casts = [
        'price' => 'float',
        'sale_price' => 'float',
        'offer_price' => 'float',
        'cost_price' => 'float',
        'profit_margin' => 'float',
        'stock' => 'integer',
        'reorder_point' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(VariantInventory::class, 'variant_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class, 'variant_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class, 'variant_id');
    }
}
