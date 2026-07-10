<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'campaign_name',
        'code',
        'discount',
        'type',
        'start_date',
        'end_date',
        'status',
        'image',
        'uploaded_by',
        'usage_limit',
        'usage_limit_per_user',
        'times_used',
        'min_order_amount',
        'max_discount',
        'applicable_to_categories',
        'applicable_to_products',
        'free_shipping',
        'stackable',
        'auto_apply',
        'priority',
    ];

    protected $casts = [
        'status' => 'boolean',
        'free_shipping' => 'boolean',
        'stackable' => 'boolean',
        'auto_apply' => 'boolean',
        'discount' => 'float',
        'min_order_amount' => 'float',
        'max_discount' => 'float',
        'times_used' => 'integer',
        'usage_limit' => 'integer',
        'usage_limit_per_user' => 'integer',
        'priority' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class, 'coupon_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(SaasUser::class, 'uploaded_by');
    }
}
