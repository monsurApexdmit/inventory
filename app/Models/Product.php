<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
        'reorder_point',
        'is_bundle',
        'bundle_price_override',
        'tracking_type',
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
        'reorder_point' => 'integer',
        'is_bundle' => 'boolean',
        'bundle_price_override' => 'float',
        'published' => 'boolean',
        'is_featured' => 'boolean',
        'is_hot_deal' => 'boolean',
        'is_best_seller' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $base = Str::slug($product->name ?: 'product') ?: 'product';
                $slug = $base;
                $i = 2;
                while (static::where('company_id', $product->company_id)->where('slug', $slug)->exists()) {
                    $slug = "{$base}-{$i}";
                    $i++;
                }
                $product->slug = $slug;
            }
        });
    }

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

    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_product_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }
}
