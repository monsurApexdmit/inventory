<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'category_id',
        'vendor_id',
        'location_id',
        'name',
        'slug',
        'description',
        'price',
        'sale_price',
        'offer_price',
        'offer_type',
        'cost_price',
        'profit_margin',
        'margin_type',
        'stock',
        'sku',
        'barcode',
        'barcode_image_path',
        'published',
        'is_featured',
        'is_hot_deal',
        'is_best_seller',
        'deal_label',
        'receipt_number',
        'image',
    ];

    protected $casts = [
        'price' => 'float',
        'sale_price' => 'float',
        'offer_price' => 'float',
        'cost_price' => 'float',
        'profit_margin' => 'float',
        'stock' => 'integer',
        'published' => 'boolean',
        'is_featured' => 'boolean',
        'is_hot_deal' => 'boolean',
        'is_best_seller' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class)->latest();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_attributes');
    }
}
