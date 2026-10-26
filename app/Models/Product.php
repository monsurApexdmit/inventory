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
        'unit_id',
        'brand_id',
        'vendor_id',
        'location_id',
        'name',
        'slug',
        'description',
        'price',
        'sale_price',
        'offer_price',
        'offer_type',
        'offer_ends_at',
        'wholesale_price',
        'min_wholesale_qty',
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
        'barcode_type',
        'barcode_image_path',
        'published',
        'is_featured',
        'is_hot_deal',
        'is_best_seller',
        'deal_label',
        'receipt_number',
        'available_in_pos',
        'visible_online',
        'tag',
        'image',
    ];

    protected $casts = [
        'price' => 'float',
        'sale_price' => 'float',
        'offer_price' => 'float',
        'offer_ends_at' => 'date:Y-m-d',
        'wholesale_price' => 'float',
        'min_wholesale_qty' => 'integer',
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
        'available_in_pos' => 'boolean',
        'visible_online' => 'boolean',
    ];

    /** True while the offer end date (inclusive, end of day) has not passed; no end date = open. */
    public function isOfferWindowOpen(): bool
    {
        return $this->offer_ends_at === null || now()->startOfDay()->lte($this->offer_ends_at->copy()->startOfDay());
    }

    /** Offer is live when offer_price is set and the end date (if any) has not passed. */
    public function isOfferActive(): bool
    {
        if ($this->offer_price === null || (float) $this->offer_price <= 0) {
            return false;
        }

        return $this->isOfferWindowOpen();
    }

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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
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
