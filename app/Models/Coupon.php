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
        'channel_pos',
        'channel_online',
        'once_per_customer',
    ];

    protected $casts = [
        'status' => 'boolean',
        'free_shipping' => 'boolean',
        'stackable' => 'boolean',
        'auto_apply' => 'boolean',
        'channel_pos' => 'boolean',
        'channel_online' => 'boolean',
        'once_per_customer' => 'boolean',
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

    /** Last moment the coupon can be used: the end of its end date's day (null = no end). */
    public function endsAt(): ?\Carbon\Carbon
    {
        return $this->end_date ? $this->end_date->copy()->endOfDay() : null;
    }

    public function isUsedUp(): bool
    {
        return $this->usage_limit !== null && (int) $this->times_used >= (int) $this->usage_limit;
    }

    /** disabled -> used_up -> scheduled -> expired -> active */
    public function phase(): string
    {
        if (!$this->status) return 'disabled';
        if ($this->isUsedUp()) return 'used_up';
        $now = \Carbon\Carbon::now();
        if ($this->start_date && $now->lt($this->start_date)) return 'scheduled';
        $end = $this->endsAt();
        if ($end && $now->gt($end)) return 'expired';
        return 'active';
    }

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
