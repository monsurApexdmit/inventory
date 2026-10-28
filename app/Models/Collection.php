<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Storefront collection: a curated (manual) or rule-based (automatic) group of products.
 * Manual membership + order lives in the `collection_product` pivot.
 */
class Collection extends Model
{
    public const TYPES = ['manual', 'automatic'];
    public const MATCHES = ['all', 'any'];
    public const SORTS = ['manual', 'best_selling', 'price_asc', 'price_desc', 'newest', 'name'];

    protected $table = 'collections';

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'description',
        'image',
        'type',
        'match',
        'rules',
        'sort_order',
        'product_sort',
        'is_published',
        'show_on_homepage',
        'seo_title',
        'seo_description',
    ];

    protected $casts = [
        'rules' => 'array',
        'sort_order' => 'integer',
        'is_published' => 'boolean',
        'show_on_homepage' => 'boolean',
    ];

    public function isManual(): bool
    {
        return $this->type !== 'automatic';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Manual products in their saved order. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_product')
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('collection_product.position');
    }
}
